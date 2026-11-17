<?php

namespace App\Services\Cameras;

use App\Models\Camera;
use App\Services\Privacy\PrivacyMask;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Near-live camera wall. While someone has the wall open, the site's edge uploads one frame per camera every
 * few seconds; when nobody is watching, nothing is uploaded (bandwidth on site connections is scarce).
 */
class CameraWall
{
    public const PREVIEW_INTERVAL = 3;

    private const WATCH_TTL = 20;

    public function markWatched(int $siteId): void
    {
        Cache::put("camera-wall:watching:{$siteId}", true, self::WATCH_TTL);
    }

    public function isWatched(int $siteId): bool
    {
        return (bool) Cache::get("camera-wall:watching:{$siteId}", false);
    }

    public function storePreview(Camera $camera, UploadedFile $image): void
    {
        Storage::disk(config('seguria.media_disk'))->put($this->previewPath($camera), app(PrivacyMask::class)->apply($camera, $image->get()));
        Cache::put("camera-wall:preview-at:{$camera->id}", now()->timestamp, now()->addDay());
    }

    public function previewPath(Camera $camera): string
    {
        return "previews/{$camera->id}.jpg";
    }

    public function previewAt(Camera $camera): ?Carbon
    {
        $timestamp = Cache::get("camera-wall:preview-at:{$camera->id}");

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
