<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * One virtual patrol round over a site's cameras.
 *
 * findings: [{camera_id, camera, kind, severity, description, confidence, frame_path}]
 * skipped:  [{camera_id, camera, reason}]
 */
class Patrol extends Model
{
    public const KIND_LABELS = [
        'persona' => 'Persona',
        'vehiculo' => 'Vehículo',
        'porton_abierto' => 'Portón o puerta abierta',
        'luz_apagada' => 'Luz apagada',
        'objeto' => 'Objeto fuera de lugar',
        'dano' => 'Daño en cerco o muro',
        'fuego_humo' => 'Fuego o humo',
        'agua' => 'Agua o inundación',
        'imagen' => 'Imagen con problemas',
        'otro' => 'Otra novedad',
    ];

    protected $fillable = [
        'site_id', 'trigger', 'requested_by', 'status', 'cameras_total', 'cameras_checked',
        'findings', 'skipped', 'summary', 'incident_id', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'skipped' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * What the console shows of a patrol round.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger,
            'requested_by' => $this->requester?->name,
            'status' => $this->status,
            'summary' => $this->summary,
            'cameras_total' => $this->cameras_total,
            'cameras_checked' => $this->cameras_checked,
            'started_at' => $this->started_at->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'incident_id' => $this->incident_id,
            'findings' => collect($this->findings ?? [])->map(fn (array $finding): array => [
                'camera' => $finding['camera'],
                'kind' => self::KIND_LABELS[$finding['kind']] ?? $finding['kind'],
                'severity' => $finding['severity'],
                'description' => $finding['description'],
                'frame_url' => URL::temporarySignedRoute('patrols.frame', now()->addHours(12), ['patrol' => $this->id, 'camera' => $finding['camera_id']]),
            ])->all(),
            'skipped' => $this->skipped ?? [],
        ];
    }
}
