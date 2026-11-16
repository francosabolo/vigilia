<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Second step of the login: the password was right, now the code from the authenticator app.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'login.two_factor';

    public function create(Request $request): Response|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return to_route('login');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return to_route('login');
        }

        $data = $request->validate(['code' => ['nullable', 'string'], 'recovery_code' => ['nullable', 'string']]);
        $key = "two-factor-challenge:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Demasiados intentos. Esperá un minuto y probá de nuevo.']);
        }

        $valid = filled($data['recovery_code'] ?? null)
            ? $twoFactor->useRecoveryCode($user, $data['recovery_code'])
            : filled($data['code'] ?? null) && $twoFactor->verify($user, $user->two_factor_secret, $data['code']);

        if (! $valid) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([filled($data['recovery_code'] ?? null) ? 'recovery_code' : 'code' => 'El código no es válido.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull(self::SESSION_KEY.'.remember', false);
        $request->session()->forget(self::SESSION_KEY);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! $pending || $pending['expires'] < now()->timestamp) {
            return null;
        }

        $user = User::find($pending['id']);

        return $user?->hasTwoFactor() ? $user : null;
    }
}
