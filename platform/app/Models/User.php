<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'client_id',
        'phone',
        'lot',
        'sector',
        'away_until',
        'last_login_at',
        'home_lat',
        'home_lng',
        'notification_prefs',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
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
            'notification_prefs' => 'array',
            'role' => UserRole::class,
            'away_until' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * The central sees cameras of every building: a stolen password must not be enough.
     */
    public function mustUseTwoFactor(): bool
    {
        return config('vigilia.require_two_factor') && $this->isCentral();
    }

    /**
     * Central staff (admins and operators) see every site of their organization.
     */
    public function isCentral(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Operator], true);
    }

    public const NOTIFICATION_CHANNELS = ['email', 'whatsapp', 'push'];

    /**
     * Extra channels on top of the in-app inbox. Everything is on until the person turns it off.
     */
    public function wantsNotificationsBy(string $channel): bool
    {
        return (bool) (($this->notification_prefs ?? [])[$channel] ?? true);
    }

    public function isClientAdmin(): bool
    {
        return $this->role === UserRole::ClientAdmin;
    }

    /**
     * Central staff and client administrations look at several sites, so they get the site switcher.
     */
    public function canSwitchSites(): bool
    {
        return $this->isCentral() || $this->isClientAdmin();
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
    public function canAccessSite(?Site $site): bool
    {
        if ($site === null || $site->trashed() || $this->isResident()) {
            return false;
        }

        if ($this->isCentral()) {
            return $this->organization_id === $site->organization_id;
        }

        if ($this->isClientAdmin()) {
            return $this->client_id !== null && $this->client_id === $site->client_id;
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

        if ($this->isClientAdmin()) {
            return $this->client_id ? Site::where('client_id', $this->client_id)->pluck('id')->all() : [];
        }

        return array_filter([$this->site_id]);
    }

    /**
     * Private broadcast channel this user listens to for live incident updates.
     */
    public function liveChannel(): string
    {
        return match (true) {
            $this->isCentral() => "operators.{$this->organization_id}",
            $this->isClientAdmin() => "clients.{$this->client_id}",
            default => "sites.{$this->site_id}",
        };
    }

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone;
    }

    /**
     * F7 — emergency contacts, in the order to call them.
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(ResidentVehicle::class)->orderBy('id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ResidentContact::class)->orderBy('position')->orderBy('id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
