<?php

namespace App\Models;

use Database\Factories\IncidentActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAction extends Model
{
    /** @use HasFactory<IncidentActionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['incident_id', 'user_id', 'type', 'channel', 'notes', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
