<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentContact extends Model
{
    protected $fillable = ['user_id', 'name', 'relation', 'phone', 'position'];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array{id: int, name: string, relation: string|null, phone: string}
     */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'relation' => $this->relation, 'phone' => $this->phone];
    }
}
