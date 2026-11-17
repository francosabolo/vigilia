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
    /** M6 — this many cameras of one property down within this many minutes, with the edge online. */
    private const MASS_OUTAGE_CAMERAS = 2;

    private const MASS_OUTAGE_MINUTES = 5;

    public const CAMERA_GRACE_SECONDS = 180;

    public function __construct(private readonly IncidentWorkflow $workflow) {}

    /**
     * @return array{opened: int, recovered: int}
     */
    public function run(): array
    {
        $opened = $recovered = 0;
        $offlineAfter = now()->subSeconds(config('vigilia.edge_offline_after'));

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
                // M5 — the gap stays on record; what happened meanwhile was kept on site and arrives now.
                $gaps = array_slice([...($edge->connectivity_gaps ?? []), ['from' => $edge->offline_since->toIso8601String(), 'to' => now()->toIso8601String()]], -10);
                $minutes = (int) $edge->offline_since->diffInMinutes(now(), absolute: true);
                $edge->forceFill(['offline_since' => null, 'connectivity_gaps' => $gaps])->save();
                $pending = $edge->queued_events ? " Tenía {$edge->queued_events} eventos guardados de ese rato: se están enviando y se analizan al llegar." : ' No quedó nada pendiente de ese rato.';
                $recovered += $this->recover(Incident::where('edge_device_id', $edge->id), "El equipo {$edge->name} volvió a reportar después de {$minutes} min sin conexión.{$pending}");
            }

            // M4 — power cut: the edge keeps working on its UPS and says so.
            if (! $isDown) {
                $powerIncident = Incident::where('edge_device_id', $edge->id)->where('source', IncidentSource::Equipment)
                    ->where('status', '!=', IncidentStatus::Resolved)->where('title', 'like', 'Corte de luz%');
                if ($edge->on_battery_since && ! $powerIncident->exists()) {
                    $this->open($edge->site_id, ['edge_device_id' => $edge->id], Severity::High,
                        "Corte de luz en {$edge->site->name}",
                        "El equipo {$edge->name} funciona con batería desde ".$edge->on_battery_since->format('H:i').'. Las cámaras sin respaldo se apagan: que la guardia revise el tablero y el perímetro.');
                    $opened++;
                } elseif (! $edge->on_battery_since && $powerIncident->exists()) {
                    $recovered += $this->recover($powerIncident, 'Volvió la luz: el equipo dejó de usar la batería.');
                }
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

        $opened += $this->detectMassOutage();

        return ['opened' => $opened, 'recovered' => $recovered];
    }

    /**
     * M6 — several cameras of a property losing their image within a few minutes while the edge is still online is
     * not a technical fault: cut cables or a signal jammer. It is raised as a possible attack, critical.
     */
    private function detectMassOutage(): int
    {
        $opened = 0;
        $recent = Camera::query()->whereNotNull('offline_since')->where('offline_since', '>=', now()->subMinutes(self::MASS_OUTAGE_MINUTES))
            ->whereHas('site', fn ($query) => $query->where('is_simulated', false))
            ->whereHas('edgeDevice', fn ($query) => $query->whereNull('offline_since'))
            ->with('site', 'edgeDevice')->get()->groupBy('site_id');

        foreach ($recent as $siteId => $cameras) {
            if ($cameras->count() < self::MASS_OUTAGE_CAMERAS) {
                continue;
            }
            $already = Incident::where('site_id', $siteId)->where('source', IncidentSource::Equipment)
                ->where('status', '!=', IncidentStatus::Resolved)->where('title', 'like', 'Posible sabotaje%')->exists();
            if ($already) {
                continue;
            }

            $names = $cameras->pluck('name')->implode(', ');
            // M4 — with the edge on battery it is a power cut, not an attack: the power-cut incident covers it.
            if ($cameras->contains(fn (Camera $camera) => $camera->edgeDevice?->on_battery_since !== null)) {
                continue;
            }
            $this->open($siteId, ['sector' => $cameras->first()->sector], Severity::Critical,
                "Posible sabotaje: {$cameras->count()} cámaras sin imagen a la vez",
                "En {$cameras->first()->site->name} se cortaron {$names} en menos de ".self::MASS_OUTAGE_MINUTES.' minutos, con el equipo edge funcionando. Puede ser un corte de cables o un inhibidor de señal: que la guardia revise el lugar ya.');
            $opened++;
        }

        return $opened;
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
