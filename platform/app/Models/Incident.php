<?php

namespace App\Models;

use App\Enums\IncidentResolution;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'new', 'source' => 'detection'];

    protected $fillable = [
        'site_id', 'camera_id', 'zone_id', 'source', 'sector', 'reported_by', 'severity', 'status', 'title', 'description', 'ai_confidence',
        'snapshot_path', 'clip_path', 'detected_at', 'notified_at', 'acknowledged_at', 'acknowledged_by',
        'escalated_at', 'resolved_at', 'resolution',
    ];

    protected function casts(): array
    {
        return [
            'source' => IncidentSource::class,
            'severity' => Severity::class,
            'status' => IncidentStatus::class,
            'resolution' => IncidentResolution::class,
            'ai_confidence' => 'float',
            'detected_at' => 'datetime',
            'notified_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function snapshotRequests(): HasMany
    {
        return $this->hasMany(SnapshotRequest::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function detectionEvents(): HasMany
    {
        return $this->hasMany(DetectionEvent::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class);
    }

    /**
     * Unguessable, expiring URL for the snapshot or clip. Used by WhatsApp (which fetches the
     * image without a session) and by the console.
     */
    public function mediaUrl(string $kind): ?string
    {
        $path = $kind === 'clip' ? $this->clip_path : $this->snapshot_path;

        if (! $path) {
            return null;
        }

        return URL::temporarySignedRoute('incidents.media', now()->addDays(7), ['incident' => $this->id, 'kind' => $kind]);
    }
}
