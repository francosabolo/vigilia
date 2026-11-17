<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The security company's clients: the administration of each consorcio or country, its sites and the people of
 * that administration who can follow them in the console. Central administrators only.
 */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $admin = $this->admin($request);

        $sites = Site::query()
            ->where('organization_id', $admin->organization_id)
            ->withCount([
                'cameras',
                'cameras as cameras_online_count' => fn ($query) => $query->where('is_online', true),
                'incidents as open_incidents_count' => fn ($query) => $query->where('status', '!=', IncidentStatus::Resolved),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site): array => [
                'id' => $site->id,
                'client_id' => $site->client_id,
                'name' => $site->name,
                'kind' => $site->kind->value,
                'position' => $site->lat !== null ? [$site->lat, $site->lng] : null,
                'cameras' => $site->cameras_count,
                'cameras_online' => $site->cameras_online_count,
                'open_incidents' => $site->open_incidents_count,
            ]);

        $clients = Client::query()
            ->where('organization_id', $admin->organization_id)
            ->with(['users' => fn ($query) => $query->where('role', UserRole::ClientAdmin)->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'address' => $client->address,
                'position' => $client->lat !== null ? [$client->lat, $client->lng] : null,
                'contact_name' => $client->contact_name,
                'contact_email' => $client->contact_email,
                'contact_phone' => $client->contact_phone,
                'notes' => $client->notes,
                'sites' => $sites->where('client_id', $client->id)->values(),
                'admins' => $client->users->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                ]),
            ]);

        return Inertia::render('clients/index', [
            'clients' => $clients,
            'unassignedSites' => $sites->whereNull('client_id')->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);

        Client::create($this->validated($request) + ['organization_id' => $admin->organization_id]);

        return back();
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->ensureOwn($this->admin($request), $client);

        $client->update($this->validated($request));

        return back();
    }

    /**
     * The sites stay, without a client; the administration's accounts are removed (they would see nothing).
     */
    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $this->ensureOwn($this->admin($request), $client);

        $client->users()->where('role', UserRole::ClientAdmin)->delete();
        $client->delete();

        return back();
    }

    public function assignSite(Request $request, Site $site): RedirectResponse
    {
        $admin = $this->admin($request);
        abort_unless($site->organization_id === $admin->organization_id, 404);

        $data = $request->validate([
            'client_id' => ['nullable', Rule::exists('clients', 'id')->where('organization_id', $admin->organization_id)],
        ]);

        $site->update(['client_id' => $data['client_id'] ?? null]);

        return back();
    }

    /**
     * Someone of the client's administration: follows every site of the client, without access to other clients.
     * Same invitation as residents and staff: they choose their own password from the e-mail.
     */
    public function invite(Request $request, Client $client): RedirectResponse
    {
        $admin = $this->admin($request);
        $this->ensureOwn($admin, $client);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        User::create($data + [
            'password' => Str::random(40),
            'role' => UserRole::ClientAdmin,
            'organization_id' => $admin->organization_id,
            'client_id' => $client->id,
        ]);

        Password::sendResetLink(['email' => $data['email']]);

        return back();
    }

    public function removeAdmin(Request $request, Client $client, User $user): RedirectResponse
    {
        $this->ensureOwn($this->admin($request), $client);
        abort_unless($user->client_id === $client->id && $user->isClientAdmin(), 404);

        $user->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:200'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'regex:/^\+?\d{8,15}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'contact_phone.regex' => 'Escribí el teléfono solo con números, con código de área. Ej: +5491155551234',
        ]);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403);

        return $user;
    }

    private function ensureOwn(User $admin, Client $client): void
    {
        abort_unless($client->organization_id === $admin->organization_id, 404);
    }
}
