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
     * @param  array{is_threat: bool, confidence: ?float, description: string, object_count?: int, suspected_guard?: bool, model?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isThreat: (bool) $data['is_threat'],
            confidence: $data['confidence'] ?? null,
            description: $data['description'],
            objectCount: $data['object_count'] ?? 1,
            suspectedGuard: $data['suspected_guard'] ?? false,
            model: $data['model'] ?? null,
        );
    }

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
