<?php

namespace App\Services\Guards;

use App\Enums\IncidentSource;
use App\Enums\UserRole;
use App\Models\Incident;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * C4 — what a security company is asked for and today cannot show: how fast each guard answered, by shift, and
 * how many alerts went to the central because nobody answered in time.
 */
class GuardPerformance
{
    /** The day shift; the rest is the night shift. */
    private const DAY_FROM = 6;

    private const DAY_TO = 18;

    /**
     * @param  array<int, int>  $siteIds
     * @return array{guards: array<int, array<string, mixed>>, shifts: array<string, array<string, mixed>>}
     */
    public function report(array $siteIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $incidents = Incident::query()
            ->whereIn('site_id', $siteIds)
            ->whereBetween('detected_at', [$from, $to])
            ->whereNotNull('notified_at')
            ->where('source', '!=', IncidentSource::Equipment)
            ->with('site:id,name,timezone')
            ->get();

        $guards = User::query()->where('role', UserRole::Guard)->whereIn('site_id', $siteIds)->with('site:id,name')->orderBy('name')->get();

        return [
            'guards' => $guards->map(function (User $guard) use ($incidents): array {
                $siteIncidents = $incidents->where('site_id', $guard->site_id);
                $mine = $siteIncidents->where('acknowledged_by', $guard->id);

                return [
                    'id' => $guard->id,
                    'name' => $guard->name,
                    'site' => $guard->site?->name,
                    'alerts' => $siteIncidents->count(),
                    'answered' => $mine->count(),
                    'avg_ack_seconds' => $this->average($mine),
                    'slowest_ack_seconds' => $mine->map(fn (Incident $incident) => $this->ackSeconds($incident))->max(),
                    'escalated' => $siteIncidents->whereNotNull('escalated_at')->count(),
                    'last_answer_at' => $mine->max('acknowledged_at')?->toIso8601String(),
                ];
            })->values()->all(),
            'shifts' => collect(['day' => 'Día (6 a 18 h)', 'night' => 'Noche (18 a 6 h)'])->map(function (string $label, string $shift) use ($incidents): array {
                $inShift = $incidents->filter(fn (Incident $incident) => $this->shift($incident) === $shift);

                return [
                    'label' => $label,
                    'alerts' => $inShift->count(),
                    'avg_ack_seconds' => $this->average($inShift->whereNotNull('acknowledged_at')),
                    'escalated' => $inShift->whereNotNull('escalated_at')->count(),
                ];
            })->all(),
        ];
    }

    private function shift(Incident $incident): string
    {
        $hour = (int) $incident->detected_at->copy()->setTimezone($incident->site?->timezone ?? config('app.timezone'))->format('G');

        return $hour >= self::DAY_FROM && $hour < self::DAY_TO ? 'day' : 'night';
    }

    private function ackSeconds(Incident $incident): ?int
    {
        return $incident->acknowledged_at ? (int) $incident->detected_at->diffInSeconds($incident->acknowledged_at, absolute: true) : null;
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     */
    private function average(Collection $incidents): ?int
    {
        $seconds = $incidents->map(fn (Incident $incident) => $this->ackSeconds($incident))->filter(fn ($value) => $value !== null);

        return $seconds->isEmpty() ? null : (int) round($seconds->avg());
    }
}
