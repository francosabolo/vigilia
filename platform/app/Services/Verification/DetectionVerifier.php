<?php

namespace App\Services\Verification;

use App\Models\Camera;
use App\Models\DetectionEvent;

interface DetectionVerifier
{
    /**
     * Decide whether a raw edge detection is a real intrusion and describe it.
     */
    public function verify(DetectionEvent $event): VerificationResult;

    /**
     * Look at an arbitrary frame of a camera (e.g. a live frame requested after a resident report).
     */
    public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult;
}
