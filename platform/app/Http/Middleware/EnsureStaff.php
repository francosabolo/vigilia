<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console routes are for security staff only; residents are sent to their own app.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isResident()) {
            return $request->expectsJson() ? abort(403) : redirect()->route('residents.home');
        }

        return $next($request);
    }
}
