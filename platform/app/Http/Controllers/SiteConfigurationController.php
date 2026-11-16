<?php

namespace App\Http\Controllers;

use App\Enums\ZoneAutonomy;
use App\Enums\ZoneKind;
use App\Models\ArmingSchedule;
use App\Models\Camera;
use App\Models\Site;
use App\Models\Zone;
use App\Services\Autonomy\AutonomyPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Site settings, camera labels, zones and arming schedules.
 */
class SiteConfigurationController extends Controller
{
    public function updateSite(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manage', $site);

        $site->update($request->validate([
            'guard_whatsapp' => ['nullable', 'string', 'regex:/^\+?\d{8,15}$/'],
            'ack_timeout_seconds' => ['required', 'integer', 'between:30,1800'],
        ]));

        return back();
    }

    public function updatePerimeter(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manage', $site);

        $data = $request->validate([
            'perimeter' => ['present', 'array', 'max:200'],
            'perimeter.*' => ['array', 'size:2'],
            'perimeter.*.0' => ['numeric', 'between:-90,90'],
            'perimeter.*.1' => ['numeric', 'between:-180,180'],
            'center' => ['nullable', 'array', 'size:2'],
            'center.0' => ['numeric', 'between:-90,90'],
            'center.1' => ['numeric', 'between:-180,180'],
        ]);

        abort_if(count($data['perimeter']) > 0 && count($data['perimeter']) < 3, 422, 'El perímetro necesita al menos 3 puntos.');

        $site->update([
            'perimeter' => $data['perimeter'],
            'lat' => $data['center'][0] ?? $site->lat,
            'lng' => $data['center'][1] ?? $site->lng,
        ]);

        return back();
    }

    /**
     * Where a camera is installed and what it sees: position, direction, field of view and useful range.
     */
    public function updatePlacement(Request $request, Camera $camera): RedirectResponse
    {
        Gate::authorize('manage', $camera->site);

        $camera->update($request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'heading' => ['required', 'integer', 'between:0,359'],
            'fov' => ['required', 'integer', 'between:10,360'],
            'range_m' => ['required', 'integer', 'between:5,300'],
        ]));

        return back();
    }

    public function updateCamera(Request $request, Camera $camera): RedirectResponse
    {
        Gate::authorize('manage', $camera->site);

        $camera->update($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
        ]));

        return back();
    }

    public function storeZone(Request $request, Camera $camera): RedirectResponse
    {
        Gate::authorize('manage', $camera->site);

        $data = $this->validateZone($request);
        $data['frigate_zone'] ??= $this->uniqueFrigateName($camera, $data['name']);

        $camera->zones()->updateOrCreate(['frigate_zone' => $data['frigate_zone']], $data);

        return back();
    }

    /**
     * Autonomy level of a zone. Automatic deterrence is refused until the zone earned it.
     */
    public function updateAutonomy(Request $request, Zone $zone, AutonomyPolicy $policy): RedirectResponse
    {
        Gate::authorize('manage', $zone->camera->site);
        $data = $request->validate(['autonomy' => ['required', Rule::enum(ZoneAutonomy::class)]]);

        $policy->set($zone, ZoneAutonomy::from($data['autonomy']));

        return back();
    }

    public function updateZone(Request $request, Zone $zone): RedirectResponse
    {
        Gate::authorize('manage', $zone->camera->site);

        $zone->update(collect($this->validateZone($request))->except('frigate_zone')->all());

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateZone(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(ZoneKind::class)],
            'frigate_zone' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'lot' => ['nullable', 'string', 'max:20'],
            'watched_labels' => ['nullable', 'array'],
            'watched_labels.*' => ['string', 'in:person,car,motorcycle,bicycle,truck,dog'],
            'coordinates' => ['nullable', 'array', 'min:3', 'max:40'],
            'coordinates.*' => ['array', 'size:2'],
            'coordinates.*.*' => ['numeric', 'between:0,1'],
        ]);
    }

    private function uniqueFrigateName(Camera $camera, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'zona';
        $candidate = $base;

        for ($i = 2; $camera->zones()->where('frigate_zone', $candidate)->exists(); $i++) {
            $candidate = "{$base}_{$i}";
        }

        return $candidate;
    }

    public function destroyZone(Zone $zone): RedirectResponse
    {
        Gate::authorize('manage', $zone->camera->site);
        $zone->delete();

        return back();
    }

    public function storeSchedule(Request $request, Zone $zone): RedirectResponse
    {
        Gate::authorize('manage', $zone->camera->site);

        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
        ]);

        $zone->armingSchedules()->create($data);

        return back();
    }

    public function destroySchedule(ArmingSchedule $schedule): RedirectResponse
    {
        Gate::authorize('manage', $schedule->zone->camera->site);
        $schedule->delete();

        return back();
    }
}
