<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentVehicle extends Model
{
    protected $fillable = ['user_id', 'site_id', 'plate', 'make_model', 'color'];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array{id: int, plate: string, make_model: string|null, color: string|null}
     */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'plate' => $this->plate, 'make_model' => $this->make_model, 'color' => $this->color];
    }
}
