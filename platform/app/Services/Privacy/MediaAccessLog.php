<?php

namespace App\Services\Privacy;

use App\Models\MediaView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * J3 — records who opened an image or clip of people. A live view refreshing every few seconds counts once
 * every 10 minutes per person and camera, so the log stays readable.
 */
class MediaAccessLog
{
    public const KINDS = [
        'incident' => 'Imagen de un incidente',
        'detection' => 'Imagen de una detección',
        'live_frame' => 'Cuadro en vivo pedido por un aviso',
        'patrol' => 'Imagen de la ronda virtual',
        'live' => 'Cámara en vivo',
    ];

    public function record(Request $request, int $siteId, string $kind, string $subject, ?string $reference = null): void
    {
        $user = $request->user();
        $key = 'media-view:'.($user?->id ?? $request->ip()).":{$kind}:{$reference}";

        if (! Cache::add($key, true, now()->addMinutes(10))) {
            return;
        }

        MediaView::create([
            'site_id' => $siteId,
            'user_id' => $user?->id,
            'kind' => $kind,
            'subject' => mb_substr($subject, 0, 255),
            'reference' => $reference,
            'ip' => $request->ip(),
            'viewed_at' => now(),
        ]);
    }
}
