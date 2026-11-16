<?php

namespace App\Services\Verification;

use App\Models\Camera;
use App\Models\DetectionEvent;

/**
 * Used when AI verification is disabled: every detection is treated as a threat.
 */
class PassthroughDetectionVerifier implements DetectionVerifier
{
    public function verify(DetectionEvent $event): VerificationResult
    {
        return new VerificationResult(
            isThreat: true,
            confidence: null,
            description: sprintf('Detección de %s (sin verificación IA).', LabelTranslator::translate($event->label)),
        );
    }

    public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
    {
        return new VerificationResult(
            isThreat: false,
            confidence: null,
            description: 'Captura recibida (sin verificación IA): la revisa la guardia.',
        );
    }
}
