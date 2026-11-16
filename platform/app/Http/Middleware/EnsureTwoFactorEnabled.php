<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins and operators must turn on two-factor before using the console.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->mustUseTwoFactor() && ! $user->hasTwoFactor()) {
            return to_route('two-factor.edit')->with('status', 'two-factor-required');
        }

        return $next($request);
    }
}
