<?php

namespace App\Http\Controllers;

use App\Models\Patrol;
use App\Models\Site;
use App\Services\Patrol\VirtualPatrol;
use App\Services\Privacy\MediaAccessLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatrolController extends Controller
{
    /**
     * "Hacer una ronda ahora": anyone of the staff who sees the site can ask for one.
     */
    public function store(Request $request, Site $site, VirtualPatrol $patrols): RedirectResponse
    {
        Gate::authorize('view', $site);

        if ($patrols->isRunning($site)) {
            throw ValidationException::withMessages(['patrol' => 'Ya hay una ronda en curso en este lugar.']);
        }

        $patrols->start($site, 'manual', $request->user());

        return back();
    }

    /**
     * Frame of a camera where the patrol found something. Protected by a signed URL.
     */
    public function frame(Request $request, Patrol $patrol, int $camera, MediaAccessLog $log): StreamedResponse
    {
        $path = collect($patrol->findings ?? [])->firstWhere('camera_id', $camera)['frame_path'] ?? null;
        $disk = Storage::disk(config('seguria.media_disk'));
        abort_unless($path && $disk->exists($path), 404);

        $finding = collect($patrol->findings)->firstWhere('camera_id', $camera);
        $log->record($request, $patrol->site_id, 'patrol', "Ronda {$patrol->id}, {$finding['camera']}", "patrol:{$patrol->id}:{$camera}");

        return $disk->response($path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }
}
