<?php

namespace App\Services\Reports;

use App\Enums\IncidentResolution;
use App\Enums\IncidentSource;
use App\Models\Incident;
use App\Models\Patrol;
use App\Models\Site;
use App\Models\SiteReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * G1 — the monthly service report a client receives: what the AI filtered, what reached the guard, how fast they
 * answered, what was confirmed, how the equipment held up. The numbers come from the database; the AI only words
 * the summary, once per month (the current month is rewritten at most every 6 hours).
 */
class MonthlyReport
{
    private const SOURCE_LABELS = [
        'detection' => 'Detecciones de las cámaras',
        'patrol' => 'Ronda virtual',
        'resident_report' => 'Avisos de vecinos',
        'panic' => 'Botón de pánico',
        'tamper' => 'Cámara movida o tapada',
        'equipment' => 'Equipos sin señal',
    ];

    public function __construct(private readonly SiteMetrics $metrics, private readonly ReportWriter $writer) {}

    public static function monthStart(string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$month}-01 00:00:00", config('app.timezone'));
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Site $site, string $month): array
    {
        $from = self::monthStart($month);
        $to = $from->endOfMonth();
        $incidents = Incident::query()->where('site_id', $site->id)->whereBetween('detected_at', [$from, $to])->orderBy('detected_at')->get();
        $equipment = $incidents->where('source', IncidentSource::Equipment);
        $patrols = Patrol::query()->where('site_id', $site->id)->whereBetween('started_at', [$from, $to])->where('status', 'done')->get();

        $data = [
            'month' => $month,
            'month_label' => $from->locale('es')->translatedFormat('F \d\e Y'),
            'is_current' => $from->isSameMonth(now()),
            'metrics' => $this->metrics->forSites([$site->id], $from, $to),
            'by_source' => $incidents->groupBy(fn (Incident $incident) => $incident->source->value)
                ->map(fn (Collection $group, string $source): array => ['label' => self::SOURCE_LABELS[$source] ?? $source, 'count' => $group->count()])
                ->values()->all(),
            'equipment' => [
                'outages' => $equipment->count(),
                'hours_down' => round($equipment->sum(fn (Incident $incident) => $incident->detected_at->diffInMinutes($incident->resolved_at ?? min(now(), $to), absolute: true)) / 60, 1),
            ],
            'patrols' => [
                'rounds' => $patrols->count(),
                'findings' => $patrols->sum(fn (Patrol $patrol) => count($patrol->findings ?? [])),
            ],
            'incidents' => $incidents->where('source', '!=', IncidentSource::Equipment)->values()->map(fn (Incident $incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'source' => self::SOURCE_LABELS[$incident->source->value] ?? $incident->source->value,
                'severity' => $incident->severity->value,
                'detected_at' => $incident->detected_at->toIso8601String(),
                'ack_seconds' => $incident->acknowledged_at ? (int) $incident->detected_at->diffInSeconds($incident->acknowledged_at, absolute: true) : null,
                'escalated' => $incident->escalated_at !== null,
                'resolution' => $incident->resolution?->value,
            ])->all(),
        ];

        $data['narrative'] = $this->narrative($site, $data);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function narrative(Site $site, array $data): string
    {
        $stored = SiteReport::where('site_id', $site->id)->where('month', $data['month'])->first();
        if ($stored && (! $data['is_current'] || $stored->generated_at->gt(now()->subHours(6)))) {
            return $stored->narrative;
        }

        $m = $data['metrics'];
        $confirmed = collect($data['incidents'])->where('resolution', IncidentResolution::Confirmed->value)->pluck('title')->all();
        $facts = [
            'lugar' => $site->name,
            'tipo' => match ($site->kind->value) {
                'country' => 'barrio cerrado', 'condominium' => 'condominio', default => 'edificio'
            },
            'mes' => $data['month_label'].($data['is_current'] ? ' (mes en curso, datos parciales)' : ''),
            'movimientos_detectados_por_las_camaras' => $m['detections'],
            'descartados_por_la_ia' => $m['filtered_by_ai'],
            'ignorados_por_estar_fuera_de_horario_o_zona' => $m['outside_schedule'],
            'alertas_a_la_guardia' => $m['incidents'],
            'escaladas_a_la_central_por_falta_de_respuesta' => $m['escalated'],
            'respuesta_promedio_de_la_guardia_en_segundos' => $m['avg_ack_seconds'],
            'intrusiones_confirmadas' => $confirmed,
            'falsas_alarmas_cerradas' => $m['false_alarms'],
            'alertas_por_origen' => $data['by_source'],
            'cortes_de_equipos' => $data['equipment']['outages'],
            'horas_sin_senal_de_equipos' => $data['equipment']['hours_down'],
            'rondas_virtuales' => $data['patrols']['rounds'],
            'novedades_de_las_rondas' => $data['patrols']['findings'],
        ];

        $text = $this->writer->write(
            'Escribí el resumen del informe mensual del servicio de seguridad, en 2 o 3 párrafos cortos. Primero cómo estuvo el mes y qué se confirmó; después cómo respondió la guardia; por último el estado de los equipos y, si hace falta, una recomendación concreta que surja de los datos.',
            $facts,
            $this->template($site, $data),
        );

        SiteReport::updateOrCreate(['site_id' => $site->id, 'month' => $data['month']], ['narrative' => $text, 'generated_at' => now()]);

        return $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function template(Site $site, array $data): string
    {
        $m = $data['metrics'];
        $confirmed = collect($data['incidents'])->where('resolution', IncidentResolution::Confirmed->value)->count();
        $ack = $m['avg_ack_seconds'] === null ? 'sin respuestas registradas' : 'en '.($m['avg_ack_seconds'] < 120 ? "{$m['avg_ack_seconds']} segundos" : round($m['avg_ack_seconds'] / 60).' minutos').' en promedio';

        return implode("\n\n", [
            "En {$data['month_label']} las cámaras de {$site->name} registraron {$m['detections']} movimientos. La IA descartó {$m['filtered_by_ai']} y otros {$m['outside_schedule']} quedaron fuera del horario o de las zonas vigiladas, así que llegaron {$m['incidents']} alertas a la guardia. Se confirmaron {$confirmed} intrusiones y se cerraron {$m['false_alarms']} falsas alarmas.",
            "La guardia respondió {$ack}; {$m['escalated']} alertas pasaron a la central por falta de respuesta a tiempo.",
            "Los equipos tuvieron {$data['equipment']['outages']} cortes, con {$data['equipment']['hours_down']} horas sin señal en total. Se hicieron {$data['patrols']['rounds']} rondas virtuales, con {$data['patrols']['findings']} novedades.",
        ]);
    }
}
