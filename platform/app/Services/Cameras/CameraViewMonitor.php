<?php

namespace App\Services\Cameras;

use App\Enums\CameraViewStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\Incident;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a reference view per camera (one for day/color, one for night/IR) and raises a tamper incident when
 * the camera is covered or turned. Two consecutive anomalous checks are required, so a person or a truck
 * passing in front of the lens does not trigger it.
 */
class CameraViewMonitor
{
    public const CHECK_INTERVAL = 300;

    private const CONFIRMATIONS = 2;

    public function __construct(private readonly ViewAnalyzer $analyzer) {}

    public function check(Camera $camera, string $frameBytes): ?Incident
    {
        $disk = Storage::disk(config('seguria.media_disk'));
        $probe = $this->analyzer->compare($frameBytes, null, $camera->fov);
        $referencePath = $this->referencePath($camera, $probe['bucket']);

        if ($probe['status'] !== 'covered' && ! $disk->exists($referencePath)) {
            $disk->put($referencePath, $frameBytes);
            $camera->forceFill(['view_checked_at' => now()])->save();

            return null;
        }

        $result = $probe['status'] === 'covered' ? $probe : $this->analyzer->compare($frameBytes, $disk->get($referencePath), $camera->fov);
        $disk->put($this->latestPath($camera), $frameBytes);

        if ($result['status'] === 'ok') {
            $camera->forceFill(['view_anomaly_count' => 0, 'view_checked_at' => now()])->save();

            return null;
        }

        $count = $camera->view_anomaly_count + 1;
        $camera->forceFill(['view_anomaly_count' => $count, 'view_checked_at' => now(), 'view_shift_deg' => $result['shift_deg']])->save();

        if ($count < self::CONFIRMATIONS || $camera->view_status !== CameraViewStatus::Ok) {
            return null;
        }

        $status = $result['status'] === 'covered' ? CameraViewStatus::Covered : CameraViewStatus::Moved;
        $camera->forceFill(['view_status' => $status])->save();

        return $this->openIncident($camera, $status, $result['shift_deg'], $frameBytes, $referencePath);
    }

    /**
     * Accept the current view as the new reference (the camera was moved on purpose, or is back in place).
     */
    public function rebaseline(Camera $camera): void
    {
        $disk = Storage::disk(config('seguria.media_disk'));

        if ($disk->exists($this->latestPath($camera))) {
            $bytes = $disk->get($this->latestPath($camera));
            $disk->put($this->referencePath($camera, $this->analyzer->compare($bytes, null, $camera->fov)['bucket']), $bytes);
        }

        $camera->forceFill(['view_status' => CameraViewStatus::Ok, 'view_anomaly_count' => 0, 'view_shift_deg' => null])->save();
    }

    public function referencePath(Camera $camera, string $bucket): string
    {
        return "references/{$camera->id}-{$bucket}.jpg";
    }

    public function latestPath(Camera $camera): string
    {
        return "references/{$camera->id}-latest.jpg";
    }

    private function openIncident(Camera $camera, CameraViewStatus $status, ?int $shiftDeg, string $frameBytes, string $referencePath): Incident
    {
        $description = match (true) {
            $status === CameraViewStatus::Covered => "La cámara {$camera->name} muestra una imagen casi uniforme: puede estar tapada, pintada o encandilada.",
            $shiftDeg !== null => sprintf('La cámara %s parece girada unos %d° hacia la %s respecto de su vista de referencia.', $camera->name, abs($shiftDeg), $shiftDeg > 0 ? 'derecha' : 'izquierda'),
            default => "Lo que ve la cámara {$camera->name} ya no coincide con su vista de referencia: puede haberse movido mucho.",
        };

        $incident = Incident::create([
            'site_id' => $camera->site_id,
            'camera_id' => $camera->id,
            'source' => IncidentSource::Tamper,
            'sector' => $camera->sector,
            'severity' => Severity::High,
            'status' => IncidentStatus::New,
            'title' => $status === CameraViewStatus::Covered ? "Cámara tapada ({$camera->name})" : "Cámara movida ({$camera->name})",
            'description' => $description,
            'detected_at' => now(),
        ]);

        // Frozen copies: the camera's latest frame and reference keep changing after this.
        $disk = Storage::disk(config('seguria.media_disk'));
        $directory = "sites/{$camera->site_id}/incidents/{$incident->id}";
        $disk->put("{$directory}/tamper-now.jpg", $frameBytes);
        if ($disk->exists($referencePath)) {
            $disk->copy($referencePath, "{$directory}/tamper-before.jpg");
        }
        $incident->forceFill(['snapshot_path' => "{$directory}/tamper-now.jpg"])->save();

        $incident->actions()->create([
            'type' => 'tamper',
            'channel' => 'system',
            'notes' => $description,
            'meta' => ['before_path' => "{$directory}/tamper-before.jpg", 'shift_deg' => $shiftDeg, 'camera_id' => $camera->id],
        ]);

        IncidentOpened::dispatch($incident);
        IncidentChanged::dispatch($incident, 'opened');

        return $incident;
    }
}
