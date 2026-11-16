<?php

namespace App\Http\Middleware;

use App\Models\EdgeDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEdgeDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $device = $token ? EdgeDevice::findByToken($token) : null;

        if (! $device) {
            return response()->json(['message' => 'Invalid edge token.'], 401);
        }

        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->save();

        $request->attributes->set('edgeDevice', $device);

        return $next($request);
    }
}
