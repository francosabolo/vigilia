<?php

namespace App\Models;

use App\Enums\SiteKind;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['kind' => 'building'];

    protected $fillable = ['organization_id', 'client_id', 'name', 'kind', 'address', 'city', 'province', 'perimeter', 'lat', 'lng', 'is_simulated', 'timezone', 'guard_whatsapp', 'ack_timeout_seconds', 'patrol_interval_minutes', 'test_mode_until', 'guard_post_max_absence_minutes', 'round_interval_minutes', 'guard_monitoring_consent_at'];

    protected function casts(): array
    {
        return [
            'kind' => SiteKind::class,
            'perimeter' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'is_simulated' => 'boolean',
            'test_mode_until' => 'datetime',
            'guard_monitoring_consent_at' => 'datetime',
        ];
    }

    /**
     * "Av. Santa Fe 1150, CABA, Ciudad Autónoma de Buenos Aires"
     */
    public function fullAddress(): string
    {
        return collect([$this->address, $this->city, $this->province])->filter()->unique()->implode(', ');
    }

    /**
     * K9 — during an installation test detections are analyzed but nobody is alerted.
     */
    public function inTestMode(): bool
    {
        return $this->test_mode_until !== null && $this->test_mode_until->isFuture();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function edgeDevices(): HasMany
    {
        return $this->hasMany(EdgeDevice::class);
    }

    public function cameras(): HasMany
    {
        return $this->hasMany(Camera::class);
    }

    public function residents(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'resident')->orderBy('lot');
    }

    public function patrols(): HasMany
    {
        return $this->hasMany(Patrol::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
