<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
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
 * The organization's staff: central (admins, operators), guards and site administrators. Admins only.
 * Residents are managed per site, in the "Vecinos" tab.
 */
class TeamController extends Controller
{
    private const STAFF_ROLES = [UserRole::Admin, UserRole::Operator, UserRole::Guard, UserRole::SiteAdmin];

    public function index(Request $request): Response
    {
        $admin = $this->admin($request);

        return Inertia::render('team/index', [
            'members' => User::query()
                ->where('organization_id', $admin->organization_id)
                ->whereIn('role', self::STAFF_ROLES)
                ->with('site:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role->value,
                    'site' => $user->site ? ['id' => $user->site->id, 'name' => $user->site->name] : null,
                    'two_factor' => $user->hasTwoFactor(),
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'is_me' => $user->is($admin),
                ]),
            'sites' => Site::where('organization_id', $admin->organization_id)->orderBy('name')->get(['id', 'name']),
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
        ], [
            'site_id.required' => 'Elegí el edificio o country donde trabaja.',
        ]);

        if (in_array($data['role'], [UserRole::Admin->value, UserRole::Operator->value], true)) {
            $data['site_id'] = null;
        }

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
