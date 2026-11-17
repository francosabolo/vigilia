<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'currency', 'price_per_camera', 'operator_hour_cost', 'usd_rate'];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
