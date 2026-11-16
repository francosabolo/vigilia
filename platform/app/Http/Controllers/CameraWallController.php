<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\Site;
use App\Services\Cameras\CameraWall;
use App\Services\Sites\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CameraWallController extends Controller
{
    /**
     * All cameras of the selected site(s) at once. The page polls every few seconds; each poll keeps the
     * site marked as "watched" so its edge keeps uploading frames.
     */
    public function index(Request $request, SiteScope $scope, CameraWall $wall): Response
    {
        if ($request->integer('site') && $request->user()->isCentral()) {
            $scope->set($request, $request->integer('site'));
        }

        $siteIds = $scope->siteIds($request);

        foreach ($siteIds as $siteId) {
            $wall->markWatched($siteId);
        }

        $sites = Site::query()
            ->whereIn('id', $siteIds)
            ->with(['cameras' => fn ($query) => $query->orderBy('sector')->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'cameras' => $site->cameras->map(function (Camera $camera) use ($wall): array {
                    $previewAt = $wall->previewAt($camera);

                    return [
                        'id' => $camera->id,
                        'name' => $camera->name,
                        'sector' => $camera->sector,
                        'is_online' => $camera->is_online,
                        'preview_url' => $previewAt ? route('cameras.preview', ['camera' => $camera, 't' => $previewAt->timestamp]) : null,
                        'preview_at' => $previewAt?->toIso8601String(),
                    ];
                }),
            ]);

        return Inertia::render('cameras/index', ['sites' => $sites, 'interval' => CameraWall::PREVIEW_INTERVAL]);
    }

    public function preview(Camera $camera, CameraWall $wall): StreamedResponse
    {
        Gate::authorize('view', $camera->site);

        $disk = Storage::disk(config('seguria.media_disk'));
        abort_unless($disk->exists($wall->previewPath($camera)), 404);

        return $disk->response($wall->previewPath($camera), headers: ['Cache-Control' => 'private, no-store']);
    }
}
