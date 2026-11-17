<?php

namespace App\Services\Reports;

use App\Models\Incident;

/**
 * B9 — the incident report for a police complaint or an insurance claim. The AI writes the account from the
 * recorded timeline; it is rewritten only when the incident changed after the last version.
 */
class IncidentReport
{
    public function __construct(private readonly ReportWriter $writer) {}

    public function narrative(Incident $incident): string
    {
        $incident->loadMissing(['site', 'camera', 'zone', 'acknowledgedBy', 'actions.user']);

        $lastChange = collect([$incident->updated_at, ...$incident->actions->pluck('created_at')])->filter()->max();
        if ($incident->report_narrative && $incident->report_generated_at?->gte($lastChange)) {
            return $incident->report_narrative;
        }

        $tz = $incident->site->timezone ?? config('app.timezone');
        $timeline = $incident->actions->sortBy('id')->map(fn ($action): array => [
            'hora' => $action->created_at?->setTimezone($tz)->format('d/m/Y H:i:s'),
            'tipo' => $action->type,
            'quien' => $action->user?->name ?? ($action->channel === 'ai' ? 'IA de Vigilia' : 'Sistema'),
            'detalle' => $action->notes,
        ])->values()->all();

        $facts = [
            'lugar' => $incident->site->name,
            'direccion' => $incident->site->address,
            'titulo' => $incident->title,
            'descripcion_de_la_ia' => $incident->description,
            'camara' => $incident->camera?->name,
            'zona' => $incident->zone?->name,
            'sector' => $incident->sector,
            'detectado' => $incident->detected_at->setTimezone($tz)->format('d/m/Y H:i:s'),
            'recibido_por_la_guardia' => $incident->acknowledged_at?->setTimezone($tz)->format('d/m/Y H:i:s'),
            'guardia' => $incident->acknowledgedBy?->name,
            'cerrado' => $incident->resolved_at?->setTimezone($tz)->format('d/m/Y H:i:s'),
            'resultado' => match ($incident->resolution?->value) {
                'confirmed' => 'Intrusión confirmada',
                'false_alarm' => 'Falsa alarma',
                'recovered' => 'El equipo se recuperó',
                default => 'Abierto',
            },
            'cronologia' => $timeline,
        ];

        $text = $this->writer->write(
            'Escribí el relato del incidente para adjuntar a una denuncia policial o a un reclamo al seguro: qué se vio, dónde, a qué hora, qué hizo cada persona y cómo terminó. En 2 a 4 párrafos, en orden cronológico, con las horas exactas de los datos.',
            $facts,
            $this->template($facts),
        );

        // Without touching updated_at, so the stored text stays valid until the incident really changes.
        $incident->timestamps = false;
        $incident->forceFill(['report_narrative' => $text, 'report_generated_at' => now()])->saveQuietly();
        $incident->timestamps = true;

        return $text;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function template(array $facts): string
    {
        $where = implode(', ', array_filter([$facts['lugar'], $facts['direccion'], $facts['camara'] ? "cámara {$facts['camara']}" : null]));
        $lines = ["El {$facts['detectado']} se registró \"{$facts['titulo']}\" en {$where}.".($facts['descripcion_de_la_ia'] ? " {$facts['descripcion_de_la_ia']}" : '')];
        if ($facts['recibido_por_la_guardia']) {
            $lines[] = 'La guardia'.($facts['guardia'] ? " ({$facts['guardia']})" : '')." tomó el aviso el {$facts['recibido_por_la_guardia']}.";
        }
        $lines[] = $facts['cerrado'] ? "El incidente se cerró el {$facts['cerrado']}. Resultado: {$facts['resultado']}." : 'Al momento de este informe el incidente sigue abierto.';

        return implode("\n\n", $lines);
    }
}
