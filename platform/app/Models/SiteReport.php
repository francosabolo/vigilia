<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteReport extends Model
{
    protected $fillable = ['site_id', 'month', 'narrative', 'generated_at', 'sent_at'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
