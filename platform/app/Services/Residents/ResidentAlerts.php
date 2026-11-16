<?php

namespace App\Services\Residents;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\SiteKind;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\Incident;
use App\Models\SnapshotRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Residents as part of the system: panic button and "I saw something" reports.
 * Both always reach the guard; a report also asks the edge for live frames of the sector so the AI can look.
 */
class ResidentAlerts
{
    public function panic(User $resident, ?string $notes = null): Incident
    {
        return $this->open($resident, IncidentSource::Panic, Severity::Critical, 'Botón de pánico '.($resident->site?->kind === SiteKind::Country ? 'del lote ' : 'de la unidad ').($resident->lot ?? 's/d'), $resident->sector, $notes);
    }

    public function report(User $resident, string $sector, ?string $notes = null): Incident
    {
        $incident = $this->open($resident, IncidentSource::ResidentReport, Severity::Medium, "Aviso de un vecino en {$sector}", $sector, $notes);

        $cameras = Camera::query()
            ->where('site_id', $resident->site_id)
            ->where('sector', $sector)
            ->whereNotNull('edge_device_id')
            ->get();

        foreach ($cameras as $camera) {
            SnapshotRequest::create([
                'edge_device_id' => $camera->edge_device_id,
                'camera_id' => $camera->id,
                'incident_id' => $incident->id,
            ]);
        }

        $incident->actions()->create([
            'type' => 'ai_review_requested',
            'channel' => 'ai',
            'notes' => $cameras->isEmpty()
                ? 'No hay cámaras en este sector: lo revisa la guardia.'
                : "La IA está revisando {$cameras->count()} cámara(s) del sector.",
        ]);

        return $incident;
    }

    private function open(User $resident, IncidentSource $source, Severity $severity, string $title, ?string $sector, ?string $notes): Incident
    {
        return DB::transaction(function () use ($resident, $source, $severity, $title, $sector, $notes): Incident {
            $incident = Incident::create([
                'site_id' => $resident->site_id,
                'source' => $source,
                'sector' => $sector,
                'reported_by' => $resident->id,
                'severity' => $severity,
                'status' => IncidentStatus::New,
                'title' => $title,
                'description' => $notes ?: ($source === IncidentSource::Panic ? 'El vecino activó el botón de pánico.' : 'Un vecino reportó algo sospechoso.'),
                'detected_at' => now(),
            ]);

            $incident->actions()->create([
                'type' => $source === IncidentSource::Panic ? 'panic' : 'resident_report',
                'user_id' => $resident->id,
                'channel' => 'app',
                'notes' => $notes,
                'meta' => ['lot' => $resident->lot],
            ]);

            IncidentOpened::dispatch($incident);
            IncidentChanged::dispatch($incident, 'opened');

            return $incident;
        });
    }
}
