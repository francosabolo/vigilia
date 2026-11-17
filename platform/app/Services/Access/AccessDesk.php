<?php

namespace App\Services\Access;

use Anthropic\Client;
use App\Models\AccessAuthorization;
use App\Models\AccessEntry;
use App\Models\Site;
use App\Models\User;
use App\Notifications\VisitorAtGate;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Verification\AiBudget;
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
     * Someone without an invitation: the resident is asked from the phone.
     */
    public function announce(Site $site, User $guard, User $resident, string $name, ?string $plate): AccessEntry
    {
        $entry = $this->entry($site, $guard, ['kind' => 'unannounced', 'name' => $name, 'plate' => $plate, 'resident_id' => $resident->id, 'status' => 'waiting']);
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

        $camera = $site->cameras()->where('is_access', true)->first();
        $disk = Storage::disk(config('seguria.media_disk'));
        if ($camera && $disk->exists($this->monitor->latestPath($camera))) {
            $path = "sites/{$site->id}/access/{$entry->id}.jpg";
            // The latest frame already went through the privacy mask when it arrived.
            $disk->copy($this->monitor->latestPath($camera), $path);
            $entry->update(['photo_path' => $path]);

            if (! $entry->plate) {
                $entry->update(['plate' => $this->readPlate($site, $disk->get($path))]);
            }
        }

        return $entry->fresh();
    }

    /**
     * A7/D3 — the AI reads the plate on the access camera; nothing when it is not sure.
     */
    public function readPlate(Site $site, string $image): ?string
    {
        if (! config('seguria.ai.enabled') || ! config('services.anthropic.key') || ! $this->budget->consume($site->id)) {
            return null;
        }

        try {
            $message = app(Client::class)->messages->create(
                model: config('seguria.ai.model'),
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
