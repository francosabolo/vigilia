<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Someone at the gate: let in with an authorization, waiting for the resident to answer, let in or turned away.
 */
class AccessEntry extends Model
{
    protected $fillable = ['site_id', 'authorization_id', 'resident_id', 'guard_id', 'kind', 'name', 'plate', 'photo_path', 'status', 'reason', 'answered_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(AccessAuthorization::class, 'authorization_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resident_id');
    }

    public function guardUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guard_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => AccessAuthorization::KINDS[$this->kind] ?? 'Sin aviso',
            'name' => $this->name,
            'plate' => $this->plate,
            'status' => $this->status,
            'reason' => $this->reason,
            'resident' => $this->resident ? ['name' => $this->resident->name, 'lot' => $this->resident->lot] : null,
            'guard' => $this->guardUser?->name,
            'photo_url' => $this->photo_path ? URL::temporarySignedRoute('access.photo', now()->addHours(12), ['accessEntry' => $this->id]) : null,
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
