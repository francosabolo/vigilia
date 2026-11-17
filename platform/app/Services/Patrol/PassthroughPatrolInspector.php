<?php

namespace App\Services\Patrol;

use App\Models\Camera;

/**
 * Used when the AI is turned off: the patrol only checks that every camera is sending an image.
 */
class PassthroughPatrolInspector implements PatrolInspector
{
    public function inspect(Camera $camera, string $current, ?string $reference, string $context): array
    {
        return ['has_novelty' => false, 'kind' => 'ninguno', 'severity' => 'baja', 'description' => 'La cámara envía imagen (IA apagada: no se analizó).', 'confidence' => null, 'model' => null];
    }
}
