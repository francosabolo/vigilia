<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * L10 — the central admin sees Vigilia exactly as another person does (a guard, a client's administration, a
 * resident) to check what they see or help them. Everything done meanwhile is done as that person, and logged.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin->role === UserRole::Admin && ! $request->session()->has(self::SESSION_KEY), 403);
        abort_unless($user->organization_id === $admin->organization_id && ! $user->is($admin), 404);
        abort_if($user->role === UserRole::Admin, 403, 'No se puede ver como otro administrador.');

        Log::notice('impersonation started', ['admin_id' => $admin->id, 'user_id' => $user->id]);
        Auth::login($user);
        $request->session()->put(self::SESSION_KEY, $admin->id);

        return redirect($user->isResident() ? route('residents.home') : route('dashboard'));
    }

    public function leave(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);
        abort_unless($adminId, 403);

        Log::notice('impersonation ended', ['admin_id' => $adminId, 'user_id' => $request->user()->id]);
        Auth::loginUsingId($adminId);

        return to_route('team.index');
    }
}
