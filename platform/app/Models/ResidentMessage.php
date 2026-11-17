<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentMessage extends Model
{
    protected $fillable = ['site_id', 'sender_id', 'body', 'recipients', 'urgent'];

    protected function casts(): array
    {
        return ['urgent' => 'boolean'];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
