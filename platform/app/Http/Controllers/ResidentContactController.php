<?php

namespace App\Http\Controllers;

use App\Models\ResidentContact;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * F7 — emergency contacts. The resident keeps their own from the app; the property's staff can add them too
 * (the resident gave them at the administration).
 */
class ResidentContactController extends Controller
{
    private const MAX = 5;

    public function storeOwn(Request $request): RedirectResponse
    {
        $this->add($request->user(), $request);

        return back();
    }

    public function destroyOwn(Request $request, ResidentContact $contact): RedirectResponse
    {
        abort_unless($contact->user_id === $request->user()->id, 404);
        $contact->delete();

        return back();
    }

    public function store(Request $request, Site $site, User $resident): RedirectResponse
    {
        Gate::authorize('manage', $site);
        abort_unless($resident->isResident() && $resident->site_id === $site->id, 404);
        $this->add($resident, $request);

        return back();
    }

    public function destroy(Site $site, ResidentContact $contact): RedirectResponse
    {
        Gate::authorize('manage', $site);
        abort_unless($contact->resident?->site_id === $site->id, 404);
        $contact->delete();

        return back();
    }

    private function add(User $resident, Request $request): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'relation' => ['nullable', 'string', 'max:50'],
            'phone' => ['required', 'string', 'regex:/^\+?\d{8,15}$/'],
        ], ['phone.regex' => 'Escribí el celular solo con números, con código de área. Ej: +5491155551234']);

        abort_if($resident->contacts()->count() >= self::MAX, 422, 'Hasta '.self::MAX.' contactos por vecino.');

        $resident->contacts()->create($data + ['position' => $resident->contacts()->count()]);
    }
}
