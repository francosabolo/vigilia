<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'organization_id',
        'site_id',
        'phone',
        'lot',
        'sector',
        'away_until',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'away_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Central staff (admins and operators) see every site of their organization.
     */
    public function isCentral(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Operator], true);
    }

    /**
     * Residents use their own app: they never see the console, cameras or images.
     */
    public function isResident(): bool
    {
        return $this->role === UserRole::Resident;
    }

    public function isAway(): bool
    {
        return $this->away_until !== null && $this->away_until->isFuture();
    }

    /**
     * Staff access to a site's console (incidents, images, configuration).
     */
    public function canAccessSite(Site $site): bool
    {
        if ($this->isResident()) {
            return false;
        }

        if ($this->isCentral()) {
            return $this->organization_id === $site->organization_id;
        }

        return $this->site_id === $site->id;
    }

    /**
     * @return array<int, int>
     */
    public function accessibleSiteIds(): array
    {
        if ($this->isResident()) {
            return [];
        }

        if ($this->isCentral()) {
            return Site::where('organization_id', $this->organization_id)->pluck('id')->all();
        }

        return array_filter([$this->site_id]);
    }

    /**
     * Private broadcast channel this user listens to for live incident updates.
     */
    public function liveChannel(): string
    {
        return $this->isCentral() ? "operators.{$this->organization_id}" : "sites.{$this->site_id}";
    }

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
