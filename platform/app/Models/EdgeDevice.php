<?php

namespace App\Models;

use Database\Factories\EdgeDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EdgeDevice extends Model
{
    /** @use HasFactory<EdgeDeviceFactory> */
    use HasFactory;

    protected $fillable = ['site_id', 'name', 'token_hash', 'version', 'zones_version', 'last_ip', 'last_seen_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /**
     * Generate a new plain-text token, store its hash and return the plain token (shown once).
     */
    public function issueToken(): string
    {
        $plain = 'edge_'.Str::random(48);
        $this->forceFill(['token_hash' => hash('sha256', $plain)])->save();

        return $plain;
    }

    public static function findByToken(string $plain): ?self
    {
        return static::where('token_hash', hash('sha256', $plain))->first();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function cameras(): HasMany
    {
        return $this->hasMany(Camera::class);
    }

    public function detectionEvents(): HasMany
    {
        return $this->hasMany(DetectionEvent::class);
    }
}
