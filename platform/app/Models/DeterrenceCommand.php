<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeterrenceCommand extends Model
{
    protected $fillable = ['incident_id', 'edge_device_id', 'camera_id', 'message', 'status', 'sent_at', 'done_at', 'result'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'done_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Camera, $this>
     */
    public function camera(): BelongsTo
    {
        return $this->belongsTo(Camera::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Incident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
