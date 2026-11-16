<?php

namespace App\Services\Incidents;

use App\Enums\IncidentResolution;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Incident;

/**
 * A security system that goes blind must say so. Raises an incident when an edge box stops reporting or a
 * camera loses its image (so the guard and the central find out), and closes it when it recovers by itself.
 */
class EquipmentWatch
{
    public const CAMERA_GRACE_SECONDS = 180;

    public function __construct(private readonly IncidentWorkflow $workflow) {}

    /**
     * @return array{opened: int, recovered: int}
     */
    public function run(): array
    {
        $opened = $recovered = 0;
        $offlineAfter = now()->subSeconds(config('seguria.edge_offline_after'));

        $edges = EdgeDevice::query()->with('site')->whereHas('site', fn ($query) => $query->where('is_simulated', false))->get();
        foreach ($edges as $edge) {
            $isDown = $edge->last_seen_at === null || $edge->last_seen_at->lt($offlineAfter);

            if ($isDown && ! $edge->offline_since) {
                $edge->forceFill(['offline_since' => $edge->last_seen_at ?? now()])->save();
                $this->open($edge->site_id, ['edge_device_id' => $edge->id], Severity::High,
                    "Equipo edge sin señal ({$edge->site->name})",
                    "El equipo {$edge->name} no reporta desde ".($edge->last_seen_at?->diffForHumans() ?? 'que se instaló').'. Las cámaras de este lugar no se están analizando.');
                $opened++;
            } elseif (! $isDown && $edge->offline_since) {
                $edge->forceFill(['offline_since' => null])->save();
                $recovered += $this->recover(Incident::where('edge_device_id', $edge->id), "El equipo {$edge->name} volvió a reportar.");
            }
        }

        $cameras = Camera::query()->with('site', 'edgeDevice')
            ->whereHas('site', fn ($query) => $query->where('is_simulated', false))
            ->get();
        foreach ($cameras as $camera) {
            // While the whole edge is down, its cameras are covered by that incident.
            if ($camera->edgeDevice?->offline_since) {
                continue;
            }

            $isDown = ! $camera->is_online && ($camera->last_seen_at === null || $camera->last_seen_at->lt(now()->subSeconds(self::CAMERA_GRACE_SECONDS)));

            if ($isDown && ! $camera->offline_since) {
                $camera->forceFill(['offline_since' => now()])->save();
                $this->open($camera->site_id, ['camera_id' => $camera->id, 'sector' => $camera->sector], Severity::Medium,
                    "Cámara sin imagen ({$camera->name})",
                    "La cámara {$camera->name} de {$camera->site->name} no envía imagen. Revisar alimentación, cable o la configuración en el grabador.");
                $opened++;
            } elseif ($camera->is_online && $camera->offline_since) {
                $camera->forceFill(['offline_since' => null])->save();
                $recovered += $this->recover(Incident::where('camera_id', $camera->id), "La cámara {$camera->name} volvió a enviar imagen.");
            }
        }

        return ['opened' => $opened, 'recovered' => $recovered];
    }

    /**
     * @param  array<string, mixed>  $subject
     */
    private function open(int $siteId, array $subject, Severity $severity, string $title, string $description): void
    {
        $incident = Incident::create($subject + [
            'site_id' => $siteId,
            'source' => IncidentSource::Equipment,
            'severity' => $severity,
            'status' => IncidentStatus::New,
            'title' => $title,
            'description' => $description,
            'detected_at' => now(),
        ]);

        $incident->actions()->create(['type' => 'equipment_down', 'channel' => 'system', 'notes' => $description]);

        IncidentOpened::dispatch($incident);
        IncidentChanged::dispatch($incident, 'opened');
    }

    private function recover($query, string $notes): int
    {
        $count = 0;
        foreach ($query->where('source', IncidentSource::Equipment)->where('status', '!=', IncidentStatus::Resolved)->get() as $incident) {
            $this->workflow->resolve($incident, IncidentResolution::Recovered, null, 'system', $notes);
            $count++;
        }

        return $count;
    }
}
