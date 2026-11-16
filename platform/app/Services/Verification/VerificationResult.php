<?php

namespace App\Services\Verification;

final class VerificationResult
{
    public function __construct(
        public readonly bool $isThreat,
        public readonly ?float $confidence,
        public readonly string $description,
        public readonly int $objectCount = 1,
        public readonly bool $suspectedGuard = false,
        public readonly ?string $model = null,
    ) {}

    /**
     * @return array{is_threat: bool, confidence: ?float, description: string, object_count: int, suspected_guard: bool, model: ?string}
     */
    public function toArray(): array
    {
        return [
            'is_threat' => $this->isThreat,
            'confidence' => $this->confidence,
            'description' => $this->description,
            'object_count' => $this->objectCount,
            'suspected_guard' => $this->suspectedGuard,
            'model' => $this->model,
        ];
    }
}
