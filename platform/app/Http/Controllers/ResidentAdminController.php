<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ResidentMessage;
use App\Models\Site;
use App\Models\User;
use App\Notifications\StaffMessageToResident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Residents are invited by the central or the site administration (no public sign-up).
 * They receive a password-reset link to set their own password.
 */
class ResidentAdminController extends Controller
{
    public function store(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manage', $site);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'lot' => ['required', 'string', 'max:20'],
            'sector' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^\+?\d{8,15}$/'],
            ...$this->homeRules($site),
        ]);

        User::create($data + [
            'password' => Str::random(40),
            'role' => UserRole::Resident,
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
        ]);

        Password::sendResetLink(['email' => $data['email']]);

        return back();
    }

    public function update(Request $request, Site $site, User $resident): RedirectResponse
    {
        Gate::authorize('manage', $site);
        abort_unless($resident->isResident() && $resident->site_id === $site->id, 404);

        $resident->update($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'lot' => ['required', 'string', 'max:20'],
            'sector' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:/^\+?\d{8,15}$/'],
            ...$this->homeRules($site),
        ]));

        return back();
    }

    /**
     * Message to one or several residents, chosen in the list or on the map. Any staff member who sees the site
     * can send it (a guard telling a neighbour their gate is open); every message is kept with its sender.
     */
    public function notify(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('view', $site);

        $data = $request->validate([
            'resident_ids' => ['required', 'array', 'min:1', 'max:2000'],
            'resident_ids.*' => ['integer'],
            'body' => ['required', 'string', 'max:500'],
            'urgent' => ['boolean'],
        ], ['resident_ids.required' => 'Elegí al menos un vecino.']);

        $residents = $site->residents()->whereIn('id', $data['resident_ids'])->get();
        abort_if($residents->isEmpty(), 422, 'Ninguno de los vecinos elegidos es de este lugar.');

        $message = ResidentMessage::create([
            'site_id' => $site->id,
            'sender_id' => $request->user()->id,
            'body' => $data['body'],
            'recipients' => $residents->count(),
            'urgent' => $data['urgent'] ?? false,
        ]);

        Notification::send($residents, new StaffMessageToResident($message, $site));

        return back()->with('notice', $residents->count() === 1 ? 'Aviso enviado.' : "Aviso enviado a {$residents->count()} vecinos.");
    }

    /**
     * A house's point on the map: only in countries and condominios.
     *
     * @return array<string, array<int, mixed>>
     */
    private function homeRules(Site $site): array
    {
        return $site->kind->hasHousesOnMap() ? [
            'home_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:home_lng'],
            'home_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:home_lat'],
        ] : [];
    }

    public function destroy(Site $site, User $resident): RedirectResponse
    {
        Gate::authorize('manage', $site);
        abort_unless($resident->isResident() && $resident->site_id === $site->id, 404);

        $resident->delete();

        return back();
    }
}
