<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchedPlate extends Model
{
    protected $table = 'plate_watchlist';

    protected $fillable = ['site_id', 'plate', 'reason', 'created_by'];

    public static function normalize(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate));
    }
}
