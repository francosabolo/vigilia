<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorController extends Controller
{
    public function edit(Request $request, TwoFactor $twoFactor): Response
    {
        $user = $request->user();
        $pending = ! $user->hasTwoFactor() && $user->two_factor_secret;

        return Inertia::render('settings/two-factor', [
            'enabled' => $user->hasTwoFactor(),
            'required' => $user->mustUseTwoFactor(),
            'setup' => $pending ? ['qr' => $twoFactor->qrSvg($user, $user->two_factor_secret), 'secret' => $user->two_factor_secret] : null,
            'recoveryCodes' => $request->session()->get('two_factor_recovery_codes'),
            'recoveryCodesLeft' => $user->hasTwoFactor() ? count($user->two_factor_recovery_codes ?? []) : null,
        ]);
    }

    /**
     * Start the setup: a new secret, unconfirmed until the user proves their app generates valid codes.
     */
    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 409);

        $user->forceFill(['two_factor_secret' => $twoFactor->newSecret(), 'two_factor_confirmed_at' => null])->save();

        return back();
    }

    public function confirm(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret || $user->hasTwoFactor() || ! $twoFactor->verify($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'El código no es válido. Revisá que la hora del celular sea correcta.']);
        }

        $codes = $twoFactor->newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();

        return to_route('two-factor.edit')->with('two_factor_recovery_codes', $codes);
    }

    public function regenerateCodes(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        abort_unless($request->user()->hasTwoFactor(), 409);

        $codes = $twoFactor->newRecoveryCodes();
        $request->user()->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return to_route('two-factor.edit')->with('two_factor_recovery_codes', $codes);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        if ($request->user()->mustUseTwoFactor()) {
            throw ValidationException::withMessages(['password' => 'Tu rol exige la verificación en dos pasos: no se puede desactivar.']);
        }

        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return to_route('two-factor.edit');
    }
}
