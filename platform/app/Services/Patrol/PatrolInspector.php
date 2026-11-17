<?php

namespace App\Services\Patrol;

use App\Models\Camera;

interface PatrolInspector
{
    /**
     * Looks at a camera's current frame, next to its usual view when there is one.
     *
     * @return array{has_novelty: bool, kind: string, severity: string, description: string, confidence: float|null, model: string|null}
     */
    public function inspect(Camera $camera, string $current, ?string $reference, string $context): array;
}
