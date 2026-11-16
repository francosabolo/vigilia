<?php

namespace App\Models;

use App\Enums\SiteKind;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['kind' => 'building'];

    protected $fillable = ['organization_id', 'name', 'kind', 'address', 'perimeter', 'lat', 'lng', 'is_simulated', 'timezone', 'guard_whatsapp', 'ack_timeout_seconds'];

    protected function casts(): array
    {
        return [
            'kind' => SiteKind::class,
            'perimeter' => 'array',
            'lat' => 'float',
            'lng' => 'float',
            'is_simulated' => 'boolean',
        ];
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

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }
}
