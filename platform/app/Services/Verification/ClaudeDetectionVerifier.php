<?php

namespace App\Services\Verification;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use App\Models\Camera;
use App\Models\DetectionEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Verifies edge detections with a Claude vision model.
 *
 * Fail-open by design: if the image is missing, the API errors or the model refuses,
 * the detection is escalated as a threat so a human decides. Losing a real intrusion
 * is worse than one extra alert.
 */
class ClaudeDetectionVerifier implements DetectionVerifier
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        Sos el analista de video de una central de monitoreo de seguridad para barrios cerrados en Argentina.
        Recibís una captura de una cámara perimetral: o bien un detector automático vio una persona o vehículo dentro
        de una zona vigilada en horario armado (nadie debería estar ahí), o bien un vecino reportó algo sospechoso y te
        pasamos el cuadro en vivo de esa cámara. Puede no haber nadie en la imagen.

        Tu tarea: decidir si es una amenaza real que amerita alertar a la guardia.
        - Amenaza real: persona o vehículo no autorizado dentro o cruzando el perímetro (alambrado, muro, portón), merodeando o trepando.
        - Si la zona es una puerta, una ventana o un punto sensible, cualquier persona manipulándola, forzándola o asomándose es amenaza.
        - No es amenaza: animales, sombras, reflejos, vegetación movida por el viento, lluvia o insectos frente al lente,
          maniquíes o carteles, guardias uniformados de ronda, vehículos de seguridad identificados.
        - Ante la duda, es amenaza con confianza media: es preferible una falsa alarma a perder una intrusión.

        La descripción va para el guardia que la lee en el celular: concreta, en español rioplatense, sin rodeos.
        Ejemplo: "2 personas trepando el alambrado del sector norte, una con mochila."
        PROMPT;

    /**
     * Tokens actually consumed, by model (used by the evaluation command to report real cost).
     *
     * @var array<string, array{calls: int, input: int, output: int}>
     */
    public array $usage = [];

    public function __construct(private readonly Client $client) {}

    public function verify(DetectionEvent $event): VerificationResult
    {
        $zones = $event->camera->zones()->whereIn('frigate_zone', $event->zones ?? [])->get()
            ->map(fn ($zone): string => "{$zone->name} ({$zone->kind->label()})")
            ->implode(', ');

        $context = sprintf(
            'Detector: %s (score %s). Zonas: %s.',
            LabelTranslator::translate($event->label),
            $event->score !== null ? number_format($event->score, 2) : 'n/d',
            $zones ?: (implode(', ', $event->zones ?? []) ?: 'cámara completa'),
        );

        return $this->review($event->snapshot_path, $event->camera, $context, sprintf(
            '%s detectada en %s.',
            ucfirst(LabelTranslator::translate($event->label)),
            $event->camera->name,
        ));
    }

    public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
    {
        return $this->review($path, $camera, $context, "Captura de {$camera->name}.");
    }

    private function review(?string $path, Camera $camera, string $context, string $fallbackSubject): VerificationResult
    {
        $image = $this->loadImage($path);

        if ($image === null) {
            return $this->fallback($fallbackSubject, 'Sin imagen para verificar.');
        }

        $context = sprintf('Cámara: %s%s. %s', $camera->name, $camera->sector ? " ({$camera->sector})" : '', $context);

        try {
            $result = $this->ask(config('seguria.ai.model'), $context, $image, $fallbackSubject);

            [$low, $high] = config('seguria.ai.review_band');
            if ($result->confidence !== null && $result->confidence >= $low && $result->confidence <= $high) {
                $result = $this->ask(config('seguria.ai.review_model'), $context, $image, $fallbackSubject);
            }

            return $result;
        } catch (APIException $exception) {
            Log::warning('AI verification failed, escalating without verdict', [
                'camera_id' => $camera->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback($fallbackSubject, 'Verificación IA no disponible.');
        }
    }

    /**
     * @param  array{data: string, media_type: string}  $image
     */
    private function ask(string $model, string $context, array $image, string $fallbackSubject): VerificationResult
    {
        $message = $this->client->messages->create(
            model: $model,
            maxTokens: 2048,
            system: self::SYSTEM_PROMPT,
            messages: [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $image['media_type'], 'data' => $image['data']]],
                    ['type' => 'text', 'text' => $context],
                ],
            ]],
            outputConfig: ['format' => DetectionVerdict::class, 'effort' => 'low'],
        );

        $this->usage[$model] ??= ['calls' => 0, 'input' => 0, 'output' => 0];
        $this->usage[$model]['calls']++;
        $this->usage[$model]['input'] += $message->usage->inputTokens + (int) $message->usage->cacheReadInputTokens;
        $this->usage[$model]['output'] += $message->usage->outputTokens;

        $verdict = $message->stopReason === 'refusal' ? null : $message->parsedOutput();

        if (! $verdict instanceof DetectionVerdict) {
            return $this->fallback($fallbackSubject, 'La IA no devolvió un veredicto.', $model);
        }

        return new VerificationResult(
            isThreat: $verdict->is_threat && ! $verdict->suspected_guard,
            confidence: max(0.0, min(1.0, $verdict->confidence)),
            description: $verdict->description,
            objectCount: max(1, $verdict->object_count),
            suspectedGuard: $verdict->suspected_guard,
            model: $model,
        );
    }

    /**
     * @return array{data: string, media_type: string}|null
     */
    private function loadImage(?string $path): ?array
    {
        $disk = Storage::disk(config('seguria.media_disk'));

        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $mediaType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return ['data' => base64_encode($disk->get($path)), 'media_type' => $mediaType];
    }

    private function fallback(string $subject, string $reason, ?string $model = null): VerificationResult
    {
        return new VerificationResult(
            isThreat: true,
            confidence: null,
            description: "{$subject} {$reason}",
            model: $model,
        );
    }
}
