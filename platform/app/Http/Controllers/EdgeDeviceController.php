<?php

namespace App\Http\Controllers;

use App\Models\EdgeDevice;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Edge boxes are registered by the central: the token is shown once and then only its hash is kept.
 */
class EdgeDeviceController extends Controller
{
    public function store(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('view', $site);
        abort_unless($request->user()->isCentral(), 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $device = new EdgeDevice(['site_id' => $site->id, 'name' => $data['name']]);
        $token = $device->issueToken();

        // Back to where it was created: the site settings or the guided setup.
        return back()
            ->with('new_edge', ['id' => $device->id, 'name' => $device->name, 'token' => $token]);
    }

    public function destroy(Request $request, Site $site, EdgeDevice $edge): RedirectResponse
    {
        Gate::authorize('view', $site);
        abort_unless($request->user()->isCentral() && $edge->site_id === $site->id, 403);

        // Cameras stay (they can be moved to a new box); they just stop receiving detections.
        $edge->delete();

        return back();
    }
}
