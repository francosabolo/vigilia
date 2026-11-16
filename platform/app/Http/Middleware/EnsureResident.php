<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureResident
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isResident()) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
