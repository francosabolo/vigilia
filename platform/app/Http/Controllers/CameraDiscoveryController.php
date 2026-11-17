<?php

namespace App\Http\Controllers;

use App\Models\CameraSetup;
use App\Models\EdgeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * K8 — the console asks the edge to look for cameras on the site's network and to add the ones the technician picks.
 */
class CameraDiscoveryController extends Controller
{
    public function request(EdgeDevice $edge): RedirectResponse
    {
        Gate::authorize('manage', $edge->site);
        $edge->update(['discovery_requested_at' => now()]);

        return back()->with('notice', 'Buscando cámaras en la red de la propiedad. Aparecen acá en unos segundos.');
    }

    public function add(Request $request, EdgeDevice $edge): RedirectResponse
    {
        Gate::authorize('manage', $edge->site);
        $data = $request->validate([
            'host' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:60'],
            'brand' => ['required', Rule::in(['hikvision', 'dahua', 'tapo', 'reolink', 'other'])],
            'user' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:100'],
            'custom_url' => ['nullable', 'required_if:brand,other', 'string', 'max:300', 'starts_with:rtsp://'],
        ]);

        CameraSetup::create([
            'edge_device_id' => $edge->id,
            'name' => Str::slug($data['name'], '_'),
            'host' => $data['host'],
            'stream_url' => CameraSetup::streamUrl($data['brand'], $data['host'], $data['user'], $data['password'], $data['custom_url'] ?? null),
            'requested_by' => $request->user()->id,
        ]);

        return back()->with('notice', "Agregando {$data['name']}. El equipo la suma a Frigate y aparece en la lista de cámaras.");
    }

    /**
     * From the edge: what the network scan found.
     */
    public function discovered(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        $data = $request->validate([
            'devices' => ['present', 'array', 'max:200'],
            'devices.*.address' => ['required', 'string', 'max:100'],
            'devices.*.name' => ['nullable', 'string', 'max:200'],
        ]);

        $device->update(['discovered' => $data['devices'], 'discovered_at' => now(), 'discovery_requested_at' => null]);

        return response()->json(['ok' => true]);
    }

    /**
     * From the edge: the camera was (or could not be) added. The stream URL with the credentials is wiped.
     */
    public function setupResult(Request $request, CameraSetup $cameraSetup): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->attributes->get('edgeDevice');
        abort_unless($cameraSetup->edge_device_id === $device->id, 404);
        $data = $request->validate(['ok' => ['required', 'boolean'], 'result' => ['nullable', 'string', 'max:255']]);

        $cameraSetup->forceFill(['status' => $data['ok'] ? 'done' : 'failed', 'result' => $data['result'] ?? null, 'stream_url' => null])->save();

        return response()->json(['ok' => true]);
    }
}
