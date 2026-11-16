<?php

namespace App\Simulation;

use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\VerificationResult;

/**
 * Stands in for the vision model during simulations. It knows the ground truth of each event and answers
 * with a configurable error rate, so the rest of the pipeline (rules, dedup, incidents, escalation) runs
 * exactly as in production. It also counts the calls that the real model would have received.
 */
class SimulatedVerifier implements DetectionVerifier
{
    /** @var array<string, Truth> */
    private array $truths = [];

    public int $calls = 0;

    public int $reviewCalls = 0;

    /**
     * @param  float  $recall  share of real intrusions the AI flags as threats
     * @param  float  $falsePassRate  share of non-threats the AI wrongly flags
     */
    public function __construct(public float $recall = 0.96, public float $falsePassRate = 0.05) {}

    public function remember(string $externalId, Truth $truth): void
    {
        $this->truths[$externalId] = $truth;
    }

    public function verify(DetectionEvent $event): VerificationResult
    {
        $this->calls++;
        $truth = $this->truths[$event->external_id] ?? Truth::Weather;

        [$isThreat, $confidence] = match ($truth) {
            Truth::Intrusion => [$this->chance($this->recall), $this->between(0.72, 0.97)],
            Truth::Loitering => [$this->chance(0.45), $this->between(0.38, 0.72)],
            default => [$this->chance($this->falsePassRate), $this->between(0.55, 0.9)],
        };

        if ($confidence >= 0.35 && $confidence <= 0.75) {
            $this->reviewCalls++;
        }

        return new VerificationResult(
            isThreat: $isThreat,
            confidence: round($isThreat ? $confidence : 1 - $confidence * 0.5, 2),
            description: $this->describe($truth, $isThreat, $event->camera?->name ?? 'la cámara'),
            objectCount: $truth === Truth::Intrusion ? random_int(1, 3) : 1,
            suspectedGuard: $truth === Truth::GuardPatrol,
            model: 'simulado',
        );
    }

    public function verifySnapshot(string $path, Camera $camera, string $context): VerificationResult
    {
        $this->calls++;

        return new VerificationResult(false, 0.7, 'Sin novedades en la imagen (simulado).', model: 'simulado');
    }

    private function describe(Truth $truth, bool $isThreat, string $camera): string
    {
        if (! $isThreat) {
            return match ($truth) {
                Truth::Animal => 'Un perro cruzando frente al alambrado.',
                Truth::GuardPatrol => 'Guardia uniformado haciendo la ronda.',
                Truth::Weather => 'Sombras y lluvia frente al lente, sin personas.',
                default => 'Sin amenaza visible.',
            };
        }

        return match ($truth) {
            Truth::Intrusion => collect([
                'Una persona trepando el alambrado, con capucha.',
                'Dos personas agachadas junto al muro, una con mochila.',
                'Una persona cruzando el perímetro hacia los lotes.',
                'Persona cortando el alambrado con una herramienta.',
            ])->random(),
            Truth::Loitering => 'Una persona caminando del lado de afuera, mira hacia el barrio.',
            default => "Posible persona en {$camera}, poca nitidez.",
        };
    }

    private function chance(float $probability): bool
    {
        return mt_rand() / mt_getrandmax() < $probability;
    }

    private function between(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}
