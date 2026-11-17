<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permission to come in: a visit (one day), a provider or domestic staff (set days and hours, until a date).
 */
class AccessAuthorization extends Model
{
    public const KINDS = ['visit' => 'Visita', 'provider' => 'Proveedor', 'staff' => 'Personal doméstico'];

    protected $fillable = [
        'site_id', 'resident_id', 'created_by', 'kind', 'name', 'document', 'plate', 'code', 'token',
        'valid_from', 'valid_until', 'days', 'time_from', 'time_to', 'notes', 'revoked_at',
    ];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_until' => 'date', 'days' => 'array', 'revoked_at' => 'datetime'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resident_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(AccessEntry::class, 'authorization_id');
    }

    /**
     * Why it does not let the person in right now, or null when it does.
     */
    public function refusal(CarbonInterface $at): ?string
    {
        $local = $at->copy()->setTimezone($this->site?->timezone ?? config('app.timezone'));

        return match (true) {
            $this->revoked_at !== null => 'La autorización fue cancelada.',
            $local->toDateString() < $this->valid_from->toDateString() => 'La autorización empieza el '.$this->valid_from->format('d/m').'.',
            $local->toDateString() > $this->valid_until->toDateString() => 'La autorización venció el '.$this->valid_until->format('d/m').'.',
            ! empty($this->days) && ! in_array($local->isoWeekday(), $this->days, true) => 'Hoy no es un día autorizado.',
            $this->time_from && $local->format('H:i') < substr($this->time_from, 0, 5) => 'Todavía no es el horario autorizado (desde las '.substr($this->time_from, 0, 5).').',
            $this->time_to && $local->format('H:i') > substr($this->time_to, 0, 5) => 'Ya pasó el horario autorizado (hasta las '.substr($this->time_to, 0, 5).').',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => self::KINDS[$this->kind] ?? $this->kind,
            'name' => $this->name,
            'document' => $this->document,
            'plate' => $this->plate,
            'code' => $this->code,
            'resident' => $this->resident ? ['name' => $this->resident->name, 'lot' => $this->resident->lot] : null,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_until' => $this->valid_until->toDateString(),
            'days' => $this->days,
            'time_from' => $this->time_from ? substr($this->time_from, 0, 5) : null,
            'time_to' => $this->time_to ? substr($this->time_to, 0, 5) : null,
            'notes' => $this->notes,
            'revoked' => $this->revoked_at !== null,
            'valid_now' => $this->refusal(now()) === null,
        ];
    }
}
