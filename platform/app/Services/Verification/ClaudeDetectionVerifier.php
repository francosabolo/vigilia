<?php

namespace App\Services\Verification;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Lib\Helpers\StructuredOutput;
use Anthropic\Messages\Message;
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
    public const SYSTEM_PROMPT = <<<'PROMPT'
        Sos el analista de video de una central de monitoreo de seguridad para barrios cerrados en Argentina.
        Recibís una captura de una cámara perimetral: o bien un detector automático vio una persona o vehículo dentro
        de una zona vigilada en horario armado (nadie debería estar ahí), o bien un vecino reportó algo sospechoso y te
        pasamos el cuadro en vivo de esa cámara. Puede no haber nadie en la imagen.

        Tu tarea: decidir si es una amenaza real que amerita alertar a la guardia.
        - Amenaza real: persona o vehículo no autorizado dentro o cruzando el perímetro (alambrado, muro, portón), merodeando o trepando.
        - Si la zona es una puerta, una ventana o un punto sensible, cualquier persona manipulándola, forzándola o asomándose es amenaza.
        - No es amenaza: animales, sombras, reflejos, vegetación movida por el viento, lluvia o insectos frente al lente,
          maniquíes o carteles, guardias uniformados de ronda, vehículos de seguridad identificados.
        - Vehículos del lado de afuera del perímetro (circulando por la calle o el camino, o sus faros encandilando)
          no son amenaza por sí solos. Solo lo son si están adentro, cruzando, o si se ve a alguien bajando o
          manipulando el alambrado o el portón.
        - Ante la duda, es amenaza con confianza media: es preferible una falsa alarma a perder una intrusión.

        La descripción va para el guardia que la lee en el celular: concreta, en español rioplatense, sin rodeos.
        Ejemplo: "2 personas trepando el alambrado del sector norte, una con mochila."
        PROMPT;

    /**
     * Added to the system prompt when the AI gets several frames of the clip instead of a single photo.
     * Kept separate so the single-photo prompt (and its evaluation) does not change.
     */
    public const SEQUENCE_ADDENDUM = <<<'PROMPT'
        Esta vez no recibís una sola captura sino varios cuadros del mismo clip, en orden temporal (el primero es el
        más antiguo). Usá el movimiento para decidir:
        - Es amenaza si la secuencia muestra a alguien acercándose al perímetro, agachándose, trepando, cortando o
          cruzando, o manipulando una puerta o ventana, aunque en algún cuadro suelto no se vea claro.
        - No es amenaza si solo pasa de largo del lado de afuera, si es un guardia haciendo su ronda, o si lo que cambia
          entre cuadros es lluvia, insectos, sombras, faros o vegetación.
        - Si la persona aparece en un solo cuadro y no se entiende qué hace, es amenaza con confianza media.
        En la descripción contá lo que pasa en la secuencia ("se acerca al alambrado y empieza a trepar").
        PROMPT;

    /**
     * Tokens actually consumed, by model (used by the evaluation command to report real cost).
     *
     * @var array<string, array{calls: int, input: int, output: int}>
     */
    public array $usage = [];

    /**
     * Optional verdict cache (evaluations): the same image, model, prompt version and context is never paid twice.
     */
    public ?VerdictCache $cache = null;

    public function __construct(private readonly Client $client) {}

    /**
     * Version of everything that shapes the answer: prompt, output schema and effort. Changing any of them
     * invalidates cached verdicts automatically.
     */
    public static function promptFingerprint(?string $systemPrompt = null): string
    {
        return substr(sha1(($systemPrompt ?? self::SYSTEM_PROMPT).json_encode(StructuredOutput::toJsonSchema(DetectionVerdict::class)).'|effort=low'), 0, 12);
    }

    public static function sequencePrompt(?string $systemPrompt = null): string
    {
        return ($systemPrompt ?? self::SYSTEM_PROMPT)."\n\n".self::SEQUENCE_ADDENDUM;
    }

    /**
     * The exact image and context a snapshot verification sends (shared by live calls and batch evaluations).
     *
     * @return array{context: string, image: array{data: string, media_type: string}}|null
     */
    public function prepareSnapshot(string $path, Camera $camera, string $context): ?array
    {
        $image = $this->loadImage($path);

        return $image === null ? null : ['context' => $this->cameraContext($camera, $context), 'image' => $image];
    }

    /**
     * @param  array{data: string, media_type: string}|array<int, array{data: string, media_type: string}>  $images  one image or frames in time order
     * @return array<string, mixed>
     */
    public function requestParams(string $model, string $context, array $images, ?string $systemPrompt = null): array
    {
        $images = isset($images['data']) ? [$images] : array_values($images);

        return [
            'model' => $model,
            'maxTokens' => 2048,
            'system' => $systemPrompt ?? self::SYSTEM_PROMPT,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ...array_map(fn (array $image): array => ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $image['media_type'], 'data' => $image['data']]], $images),
                    ['type' => 'text', 'text' => $context],
                ],
            ]],
            'outputConfig' => ['format' => DetectionVerdict::class, 'effort' => 'low'],
        ];
    }

    /**
     * Turns an API message (live or from a batch) into a verdict; null when the model gave none.
     */
    public function verdictFromMessage(Message $message, string $model): ?VerificationResult
    {
        if ($message->stopReason === 'refusal') {
            return null;
        }

        $verdict = $message->parsedOutput();
        if (! $verdict instanceof DetectionVerdict) {
            $text = collect($message->content)->first(fn ($block) => $block->type === 'text')?->text;
            $data = $text ? json_decode($text, true) : null;
            $verdict = is_array($data) ? DetectionVerdict::fromArray($data) : null;
        }

        if (! $verdict instanceof DetectionVerdict) {
            return null;
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
     * The exact text sent next to the image (part of the verdict cache key).
     */
    public function contextFor(Camera $camera, string $context): string
    {
        return $this->cameraContext($camera, $context);
    }

    private function cameraContext(Camera $camera, string $context): string
    {
        return sprintf('Cámara: %s%s. %s', $camera->name, $camera->sector ? " ({$camera->sector})" : '', $context);
    }

    public function verify(DetectionEvent $event): VerificationResult
    {
        ['images' => $images, 'sequence' => $sequence] = $this->material($event);

        return $this->review($images, $event->camera, $this->eventContext($event, $sequence ? count($images) : 0), $this->eventSubject($event), $sequence);
    }

    /**
     * Shadow mode: one call with a challenger model and/or prompt, on exactly what production saw. Never cached,
     * never counted in the site budget and never used to decide anything.
     *
     * @return array{result: VerificationResult, input_tokens: int, output_tokens: int, latency_ms: int}|null
     */
    public function challenge(DetectionEvent $event, string $model, string $systemPrompt): ?array
    {
        ['images' => $images, 'sequence' => $sequence] = $this->material($event);
        if ($images === []) {
            return null;
        }

        $context = $this->cameraContext($event->camera, $this->eventContext($event, $sequence ? count($images) : 0));
        $started = microtime(true);
        $message = $this->client->messages->create(...$this->requestParams($model, $context, $images, $sequence ? self::sequencePrompt($systemPrompt) : $systemPrompt));

        return [
            'result' => $this->verdictFromMessage($message, $model) ?? $this->fallback($this->eventSubject($event), 'La IA no devolvió un veredicto.', $model),
            'input_tokens' => $message->usage->inputTokens + (int) $message->usage->cacheReadInputTokens,
            'output_tokens' => $message->usage->outputTokens,
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * What the AI looks at: the clip's frames when the edge sent at least two, otherwise the snapshot.
     *
     * @return array{images: array<int, array{data: string, media_type: string}>, sequence: bool}
     */
    private function material(DetectionEvent $event): array
    {
        if ($event->hasSequence()) {
            $frames = array_values(array_filter(array_map(fn (string $path) => $this->loadImage($path), $event->frame_paths)));
            if (count($frames) >= 2) {
                return ['images' => $frames, 'sequence' => true];
            }
        }

        return ['images' => array_values(array_filter([$this->loadImage($event->snapshot_path)])), 'sequence' => false];
    }

    private function eventContext(DetectionEvent $event, int $frames = 0): string
    {
        $zones = $event->camera->zones()->whereIn('frigate_zone', $event->zones ?? [])->get()
            ->map(fn ($zone): string => "{$zone->name} ({$zone->kind->label()})")
            ->implode(', ');

        $sequence = '';
        if ($frames >= 2) {
            $seconds = $event->ended_at ? max(1, (int) round($event->started_at->diffInSeconds($event->ended_at))) : null;
            $sequence = $seconds ? "Secuencia de {$frames} cuadros a lo largo de {$seconds} s. " : "Secuencia de {$frames} cuadros. ";
        }

        return $sequence.sprintf(
            'Detector: %s (score %s). Zonas: %s.',
            LabelTranslator::translate($event->label),
            $event->score !== null ? number_format($event->score, 2) : 'n/d',
            $zones ?: (implode(', ', $event->zones ?? []) ?: 'cámara completa'),
        );
    }

    private function eventSubject(DetectionEvent $event): string
    {
        return sprintf('%s detectada en %s.', ucfirst(LabelTranslator::translate($event->label)), $event->camera->name);
    }

    public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
    {
        return $this->review(array_values(array_filter([$this->loadImage($path)])), $camera, $context, "Captura de {$camera->name}.", false);
    }

    /**
     * @param  array<int, array{data: string, media_type: string}>  $images
     */
    private function review(array $images, Camera $camera, string $context, string $fallbackSubject, bool $sequence): VerificationResult
    {
        if ($images === []) {
            return $this->fallback($fallbackSubject, 'Sin imagen para verificar.');
        }

        $context = $this->cameraContext($camera, $context);
        $system = $sequence ? self::sequencePrompt() : null;

        if ($camera->site_id && ! app(AiBudget::class)->consume($camera->site_id)) {
            return $this->fallback($fallbackSubject, 'Tope diario de verificaciones IA alcanzado: alerta sin verificar.');
        }

        try {
            $result = $this->ask(config('seguria.ai.model'), $context, $images, $fallbackSubject, $system);

            [$low, $high] = config('seguria.ai.review_band');
            if ($result->confidence !== null && $result->confidence >= $low && $result->confidence <= $high) {
                $result = $this->ask(config('seguria.ai.review_model'), $context, $images, $fallbackSubject, $system);
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
     * @param  array<int, array{data: string, media_type: string}>  $images
     */
    private function ask(string $model, string $context, array $images, string $fallbackSubject, ?string $systemPrompt = null): VerificationResult
    {
        // A single image keeps the original key, so cached evaluation verdicts stay valid.
        $cacheKey = $this->cache?->key($model, self::promptFingerprint($systemPrompt), $context, implode('|', array_column($images, 'data')));
        if ($cacheKey && ($cached = $this->cache->get($cacheKey))) {
            return VerificationResult::fromArray($cached['verdict']);
        }

        $params = $this->requestParams($model, $context, $images, $systemPrompt);
        $message = $this->client->messages->create(...$params);

        $usage = ['input' => $message->usage->inputTokens + (int) $message->usage->cacheReadInputTokens, 'output' => $message->usage->outputTokens];
        $this->usage[$model] ??= ['calls' => 0, 'input' => 0, 'output' => 0];
        $this->usage[$model]['calls']++;
        $this->usage[$model]['input'] += $usage['input'];
        $this->usage[$model]['output'] += $usage['output'];

        $result = $this->verdictFromMessage($message, $model) ?? $this->fallback($fallbackSubject, 'La IA no devolvió un veredicto.', $model);
        if ($cacheKey && $result->model === $model) {
            $this->cache->put($cacheKey, $result, $usage, batch: false);
        }

        return $result;
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
