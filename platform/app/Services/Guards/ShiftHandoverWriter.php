<?php

namespace App\Services\Guards;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Patrol;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\Reports\ReportWriter;

/**
 * C5 — the shift handover: what happened since the last handover (or the last 12 hours) and what is still open,
 * worded by the AI from the records, plus whatever the guard wants to add.
 */
class ShiftHandoverWriter
{
    public function __construct(private readonly ReportWriter $writer) {}

    public function handOver(Site $site, User $guard, ?string $notes): ShiftHandover
    {
        $from = ShiftHandover::where('site_id', $site->id)->latest('period_end')->value('period_end') ?? now()->subHours(12);
        $from = max($from, now()->subHours(24));
        $tz = $site->timezone ?? config('app.timezone');

        $incidents = Incident::where('site_id', $site->id)->where(fn ($query) => $query->where('detected_at', '>=', $from)->orWhere('status', '!=', IncidentStatus::Resolved))->orderBy('detected_at')->get();
        $open = $incidents->filter(fn (Incident $incident) => $incident->status->isOpen());
        $patrols = Patrol::where('site_id', $site->id)->where('started_at', '>=', $from)->where('status', 'done')->get();

        $facts = [
            'lugar' => $site->name,
            'turno_desde' => $from->copy()->setTimezone($tz)->format('d/m H:i'),
            'turno_hasta' => now()->setTimezone($tz)->format('d/m H:i'),
            'incidentes_del_turno' => $incidents->where('detected_at', '>=', $from)->map(fn (Incident $incident) => [
                'hora' => $incident->detected_at->copy()->setTimezone($tz)->format('H:i'),
                'titulo' => $incident->title,
                'estado' => $incident->status->isOpen() ? 'abierto' : ($incident->resolution?->value === 'confirmed' ? 'intrusión confirmada' : 'cerrado'),
            ])->values()->all(),
            'siguen_abiertos' => $open->pluck('title')->values()->all(),
            'rondas_virtuales' => $patrols->count(),
            'novedades_de_rondas' => $patrols->flatMap(fn (Patrol $patrol) => collect($patrol->findings ?? [])->map(fn ($finding) => "{$finding['camera']}: {$finding['description']}"))->values()->all(),
            'notas_de_la_guardia' => $notes,
        ];

        $fallback = implode(' ', array_filter([
            $incidents->where('detected_at', '>=', $from)->isEmpty() ? 'Turno sin incidentes.' : $incidents->where('detected_at', '>=', $from)->count().' incidentes en el turno.',
            $open->isEmpty() ? 'No queda nada abierto.' : 'Siguen abiertos: '.$open->pluck('title')->implode('; ').'.',
            $patrols->isNotEmpty() ? "{$patrols->count()} rondas virtuales." : null,
        ]));

        $summary = $this->writer->write(
            'Escribí la entrega de turno para la guardia que entra, en 3 a 5 oraciones cortas: qué pasó, qué sigue abierto y a qué prestar atención. Si no pasó nada, decilo en una oración.',
            $facts,
            $fallback,
        );

        return ShiftHandover::create([
            'site_id' => $site->id,
            'user_id' => $guard->id,
            'period_start' => $from,
            'period_end' => now(),
            'summary' => $summary,
            'notes' => $notes,
            'open_incidents' => $open->count(),
        ]);
    }
}
