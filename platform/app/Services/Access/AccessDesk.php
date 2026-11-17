<?php

namespace App\Services\Access;

use Anthropic\Client;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Jobs\CheckVisitorExit;
use App\Models\AccessAuthorization;
use App\Models\AccessEntry;
use App\Models\Incident;
use App\Models\ResidentVehicle;
use App\Models\Site;
use App\Models\User;
use App\Models\WatchedPlate;
use App\Notifications\VisitorAtGate;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Verification\AiBudget;
use App\Services\Visits\VisitWatch;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The gate: D1 visits invited with a code or QR, D2 providers and domestic staff on set days, D3 every entry with
 * the photo of the access camera and the plate read by the AI, and visitors without notice announced to the
 * resident, who lets them in from the phone.
 */
class AccessDesk
{
    public function __construct(private readonly CameraViewMonitor $monitor, private readonly AiBudget $budget) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function authorize(Site $site, array $data, User $creator, ?User $resident): AccessAuthorization
    {
        return AccessAuthorization::create($data + [
            'site_id' => $site->id,
            'resident_id' => $resident?->id,
            'created_by' => $creator->id,
            'code' => $this->uniqueCode($site),
            'token' => (string) Str::uuid(),
        ]);
    }

    /**
     * The guard types the code (or scans the QR): let in, or turned away with the reason.
     */
    public function checkIn(Site $site, string $codeOrToken, User $guard, ?string $plate = null): AccessEntry
    {
        $authorization = AccessAuthorization::query()
            ->where('site_id', $site->id)
            ->where(fn ($query) => $query->where('code', $codeOrToken)->orWhere('token', $codeOrToken))
            ->latest('id')
            ->first();

        if (! $authorization) {
            return $this->entry($site, $guard, ['kind' => 'unknown', 'name' => "Código {$codeOrToken}", 'status' => 'denied', 'reason' => 'El código no existe en esta propiedad.', 'plate' => $plate]);
        }

        $refusal = $authorization->refusal(now());
        $entry = $this->entry($site, $guard, [
            'authorization_id' => $authorization->id,
            'resident_id' => $authorization->resident_id,
            'unit' => $authorization->resident?->lot,
            'visiting' => $authorization->resident?->name,
            'kind' => $authorization->kind,
            'name' => $authorization->name,
            'plate' => $plate ?: $authorization->plate,
            'status' => $refusal ? 'denied' : 'allowed',
            'reason' => $refusal,
        ]);

        if (! $refusal && $authorization->resident) {
            $authorization->resident->notify(new VisitorAtGate($entry));
        }

        return $entry;
    }

    /**
     * D5 — the guard registers a visit at the gate: who, to which unit, to see whom. Let in right away, or the
     * registered resident is asked first from the phone.
     *
     * @param  array{name: string, document?: string|null, plate?: string|null, unit: string, resident_id?: int|null, visiting?: string|null, purpose?: string|null}  $data
     */
    public function registerVisit(Site $site, User $guard, array $data, bool $askResident): AccessEntry
    {
        $resident = ! empty($data['resident_id']) ? User::find($data['resident_id']) : null;
        $entry = $this->entry($site, $guard, [
            'kind' => 'unannounced',
            'name' => $data['name'],
            'document' => $data['document'] ?? null,
            'plate' => ! empty($data['plate']) ? strtoupper($data['plate']) : null,
            'unit' => $data['unit'],
            'resident_id' => $resident?->id,
            'visiting' => $resident?->name ?? ($data['visiting'] ?? null),
            'purpose' => $data['purpose'] ?? null,
            'status' => $askResident && $resident ? 'waiting' : 'allowed',
            'reason' => $askResident && $resident ? null : 'Registrada por la guardia.',
        ]);

        if ($resident) {
            $resident->notify(new VisitorAtGate($entry));
        }

        return $entry;
    }

    public function exit(AccessEntry $entry): void
    {
        $entry->update(['exited_at' => now()]);

        // D8 — the picture on the way out, to compare with the one on the way in.
        $camera = $entry->site->cameras()->where('is_access', true)->first();
        $disk = Storage::disk(config('vigilia.media_disk'));
        if ($camera && $entry->photo_path && $disk->exists($this->monitor->latestPath($camera))) {
            $path = "sites/{$entry->site_id}/access/{$entry->id}-exit.jpg";
            $disk->copy($this->monitor->latestPath($camera), $path);
            $entry->update(['exit_photo_path' => $path]);
            CheckVisitorExit::dispatch($entry->fresh());
        }
    }

    /**
     * Someone without an invitation: the resident is asked from the phone.
     */
    public function announce(Site $site, User $guard, User $resident, string $name, ?string $plate): AccessEntry
    {
        $entry = $this->entry($site, $guard, ['kind' => 'unannounced', 'name' => $name, 'plate' => $plate, 'resident_id' => $resident->id, 'unit' => $resident->lot, 'visiting' => $resident->name, 'status' => 'waiting']);
        $resident->notify(new VisitorAtGate($entry));

        return $entry;
    }

    public function answer(AccessEntry $entry, bool $allow, ?User $by = null, ?string $reason = null): void
    {
        if ($entry->status !== 'waiting') {
            return;
        }

        $entry->update(['status' => $allow ? 'allowed' : 'denied', 'reason' => $reason ?? ($allow ? null : 'El vecino no autorizó el ingreso.'), 'answered_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function entry(Site $site, User $guard, array $data): AccessEntry
    {
        $entry = AccessEntry::create($data + ['site_id' => $site->id, 'guard_id' => $guard->id]);
        // D7 — when the visit should be over.
        $entry->update(['expected_until' => app(VisitWatch::class)->expectedUntil($entry, $site)]);

        $camera = $site->cameras()->where('is_access', true)->first();
        $disk = Storage::disk(config('vigilia.media_disk'));
        if ($camera && $disk->exists($this->monitor->latestPath($camera))) {
            $path = "sites/{$site->id}/access/{$entry->id}.jpg";
            // The latest frame already went through the privacy mask when it arrived.
            $disk->copy($this->monitor->latestPath($camera), $path);
            $entry->update(['photo_path' => $path]);

            if (! $entry->plate) {
                $entry->update(['plate' => $this->readPlate($site, $disk->get($path))]);
            }
        }

        $this->checkWatchlist($site, $entry->fresh());
        $this->recognizeResidentVehicle($site, $entry->fresh());

        return $entry->fresh();
    }

    /**
     * F10 — the plate belongs to a resident's car: the entry says whose it is.
     */
    private function recognizeResidentVehicle(Site $site, AccessEntry $entry): void
    {
        if (! $entry->plate) {
            return;
        }
        $vehicle = ResidentVehicle::where('site_id', $site->id)->where('plate', WatchedPlate::normalize($entry->plate))->with('resident:id,name,lot')->first();
        if ($vehicle && $entry->resident_id === null) {
            $entry->update(['reason' => trim(($entry->reason ? $entry->reason.' ' : '')."Auto de {$vehicle->resident->name}".($vehicle->resident->lot ? " ({$vehicle->resident->lot})" : '').'.')]);
        }
    }

    /**
     * A7 — a plate on the site's watchlist at the gate: the guard and the central get an alert at once.
     */
    private function checkWatchlist(Site $site, AccessEntry $entry): void
    {
        if (! $entry->plate) {
            return;
        }
        $watched = WatchedPlate::where('site_id', $site->id)->where('plate', WatchedPlate::normalize($entry->plate))->first();
        if (! $watched) {
            return;
        }

        $incident = Incident::create([
            'site_id' => $site->id,
            'camera_id' => $site->cameras()->where('is_access', true)->value('id'),
            'source' => IncidentSource::Detection,
            'severity' => Severity::High,
            'status' => IncidentStatus::New,
            'title' => "Patente en alerta en el acceso: {$watched->plate}",
            'description' => trim("{$entry->name} llegó al acceso con la patente {$watched->plate}. ".($watched->reason ? "Motivo de la alerta: {$watched->reason}." : '')),
            'snapshot_path' => $entry->photo_path,
            'detected_at' => now(),
        ]);
        $incident->actions()->create(['type' => 'detected', 'channel' => 'system', 'notes' => $incident->description]);
        IncidentOpened::dispatch($incident);
        IncidentChanged::dispatch($incident, 'opened');
    }

    /**
     * A7/D3 — the AI reads the plate on the access camera; nothing when it is not sure.
     */
    public function readPlate(Site $site, string $image): ?string
    {
        if (! config('vigilia.ai.enabled') || ! config('services.anthropic.key') || ! $this->budget->consume($site->id)) {
            return null;
        }

        try {
            $message = app(Client::class)->messages->create(
                model: config('vigilia.ai.model'),
                maxTokens: 256,
                system: 'Leés patentes de vehículos en el acceso de un edificio o barrio en Argentina. Si no estás seguro de todos los caracteres, devolvé la patente vacía.',
                messages: [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($image)]],
                    ['type' => 'text', 'text' => 'Cámara del acceso. ¿Hay un vehículo y cuál es su patente?'],
                ]]],
                outputConfig: ['format' => PlateVerdict::class, 'effort' => 'low'],
            );
            $verdict = $message->parsedOutput();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $plate = $verdict instanceof PlateVerdict && $verdict->vehicle_present ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $verdict->plate)) : '';

        return $plate !== '' ? $plate : null;
    }

    private function uniqueCode(Site $site): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (AccessAuthorization::where('site_id', $site->id)->where('code', $code)->whereNull('revoked_at')->where('valid_until', '>=', now()->toDateString())->exists());

        return $code;
    }
}
