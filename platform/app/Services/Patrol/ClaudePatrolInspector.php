<?php

namespace App\Services\Patrol;

use Anthropic\Client;
use App\Models\Camera;
use App\Models\Patrol;

/**
 * Claude compares what a camera shows now with its usual view, like a guard doing rounds on the monitors.
 */
class ClaudePatrolInspector implements PatrolInspector
{
    public const SYSTEM_PROMPT = <<<'PROMPT'
Sos el guardia que hace la ronda por las cámaras de un edificio o un barrio cerrado en Argentina.
Por cada cámara recibís la imagen ACTUAL y, si existe, la VISTA HABITUAL de esa cámara (cómo se ve normalmente).
Tu trabajo es encontrar lo que está fuera de lo normal y merece que la guardia lo mire: un portón o una puerta abiertos,
una luz que suele estar prendida y está apagada, un vehículo u objeto que no estaba, daño en el cerco o el muro,
fuego, humo, agua, una persona donde no debería haber nadie a esta hora, o una imagen que no sirve (borrosa, tapada, encandilada).

Reglas:
- Compará con la vista habitual: lo que ya estaba ahí no es novedad. Los cambios de luz del día, sombras, lluvia,
  hojas, animales y el ruido de la cámara no son novedades.
- Una persona o un vehículo en horario de actividad normal no es novedad, salvo que haga algo raro.
- Si no hay nada fuera de lo normal, respondé has_novelty=false y kind=ninguno.
- Sé concreto en la descripción: qué y dónde en la imagen. Nunca inventes lo que no se ve.
PROMPT;

    public function __construct(private readonly Client $client) {}

    public function inspect(Camera $camera, string $current, ?string $reference, string $context): array
    {
        $model = config('seguria.ai.model');
        $content = [];

        if ($reference !== null) {
            $content[] = ['type' => 'text', 'text' => 'VISTA HABITUAL de la cámara:'];
            $content[] = $this->image($reference);
        }
        $content[] = ['type' => 'text', 'text' => 'Imagen ACTUAL:'];
        $content[] = $this->image($current);
        $content[] = ['type' => 'text', 'text' => $context];

        $message = $this->client->messages->create(
            model: $model,
            maxTokens: 1024,
            system: self::SYSTEM_PROMPT,
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: ['format' => PatrolVerdict::class, 'effort' => 'low'],
        );

        $verdict = $message->stopReason === 'refusal' ? null : $message->parsedOutput();

        if (! $verdict instanceof PatrolVerdict) {
            return ['has_novelty' => false, 'kind' => 'imagen', 'severity' => 'baja', 'description' => 'La IA no pudo revisar esta cámara en la ronda.', 'confidence' => null, 'model' => $model];
        }

        $kind = array_key_exists($verdict->kind, Patrol::KIND_LABELS) ? $verdict->kind : ($verdict->has_novelty ? 'otro' : 'ninguno');

        return [
            'has_novelty' => $verdict->has_novelty && $kind !== 'ninguno',
            'kind' => $kind,
            'severity' => in_array($verdict->severity, ['baja', 'media', 'alta'], true) ? $verdict->severity : 'media',
            'description' => $verdict->description,
            'confidence' => max(0.0, min(1.0, $verdict->confidence)),
            'model' => $model,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function image(string $bytes): array
    {
        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($bytes)]];
    }
}
