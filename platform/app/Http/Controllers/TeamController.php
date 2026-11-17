<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Usuarios: every account of the organization. Staff and client administrations are managed here; residents are
 * listed too but edited in their site's "Vecinos" tab. Central admins only.
 */
class TeamController extends Controller
{
    private const STAFF_ROLES = [UserRole::Admin, UserRole::Operator, UserRole::Guard, UserRole::SiteAdmin, UserRole::ClientAdmin];

    /**
     * Every account of the organization, residents included (those are edited from their site's "Vecinos" tab,
     * where their unit and sector live). Searchable by name or e-mail, filterable by role.
     */
    public function index(Request $request): Response
    {
        $admin = $this->admin($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);

        $base = User::query()->where('organization_id', $admin->organization_id);
        $members = (clone $base)
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('lot', $q)->orWhereHas('vehicles', fn ($query) => $query->where('plate', 'like', '%'.strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q)).'%'))))
            ->with(['site:id,name', 'client:id,name'])
            ->orderBy('name')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role->value,
                'site' => $user->site ? ['id' => $user->site->id, 'name' => $user->site->name] : null,
                'client' => $user->client ? ['id' => $user->client->id, 'name' => $user->client->name] : null,
                'lot' => $user->lot,
                'two_factor' => $user->hasTwoFactor(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'is_me' => $user->is($admin),
            ]);

        return Inertia::render('team/index', [
            'members' => $members,
            'filters' => ['q' => $filters['q'] ?? '', 'role' => $filters['role'] ?? ''],
            'counts' => (clone $base)->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
            'sites' => Site::where('organization_id', $admin->organization_id)->orderBy('name')->get(['id', 'name']),
            'clients' => Client::where('organization_id', $admin->organization_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $data = $this->validated($request, $admin, creating: true);

        User::create($data + [
            'password' => Str::random(40),
            'organization_id' => $admin->organization_id,
        ]);

        // Same flow as residents: they choose their own password from the e-mail.
        Password::sendResetLink(['email' => $data['email']]);

        return back();
    }

    public function update(Request $request, User $member): RedirectResponse
    {
        $admin = $this->admin($request);
        $this->ensureMember($admin, $member);

        $data = $this->validated($request, $admin, creating: false);
        if ($member->is($admin) && $data['role'] !== UserRole::Admin->value) {
            throw ValidationException::withMessages(['role' => 'No podés quitarte el rol de administrador a vos mismo.']);
        }

        $member->update($data);

        return back();
    }

    public function destroy(Request $request, User $member): RedirectResponse
    {
        $admin = $this->admin($request);
        $this->ensureMember($admin, $member);
        abort_if($member->is($admin), 422, 'No podés quitarte a vos mismo.');

        $member->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, User $admin, bool $creating): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            ...($creating ? ['email' => ['required', 'email', 'max:255', 'unique:users,email']] : []),
            'phone' => ['nullable', 'string', 'regex:/^\+?\d{8,15}$/'],
            'role' => ['required', Rule::enum(UserRole::class)->only(self::STAFF_ROLES)],
            // Guards and site administrators belong to one building or country; the central sees all.
            'site_id' => [
                Rule::requiredIf(fn () => in_array($request->input('role'), [UserRole::Guard->value, UserRole::SiteAdmin->value], true)),
                'nullable',
                Rule::exists('sites', 'id')->where('organization_id', $admin->organization_id),
            ],
            'client_id' => [
                Rule::requiredIf(fn () => $request->input('role') === UserRole::ClientAdmin->value),
                'nullable',
                Rule::exists('clients', 'id')->where('organization_id', $admin->organization_id),
            ],
        ], [
            'site_id.required' => 'Elegí la propiedad donde trabaja.',
            'client_id.required' => 'Elegí el cliente al que pertenece.',
        ]);

        if (! in_array($data['role'], [UserRole::Guard->value, UserRole::SiteAdmin->value], true)) {
            $data['site_id'] = null;
        }
        $data['client_id'] = $data['role'] === UserRole::ClientAdmin->value ? ($data['client_id'] ?? null) : null;

        return $data;
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->role === UserRole::Admin, 403);

        return $user;
    }

    private function ensureMember(User $admin, User $member): void
    {
        abort_unless($member->organization_id === $admin->organization_id && in_array($member->role, self::STAFF_ROLES, true), 404);
    }
}
