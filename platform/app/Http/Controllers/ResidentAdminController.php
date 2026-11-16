<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        ]));

        return back();
    }

    public function destroy(Site $site, User $resident): RedirectResponse
    {
        Gate::authorize('manage', $site);
        abort_unless($resident->isResident() && $resident->site_id === $site->id, 404);

        $resident->delete();

        return back();
    }
}
