<?php

namespace App\Http\Controllers;

use Anthropic\Client;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\CameraSetup;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Site;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Cameras\FramingVerdict;
use App\Services\Sites\SiteScope;
use App\Services\Verification\AiBudget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Equipment: K13 inventory of cameras and edge boxes per client, K10 framing review by the AI, K12 replacing a
 * broken camera keeping its zones and history, E6 updating an edge box remotely.
 */
class EquipmentController extends Controller
{
    public function index(Request $request, SiteScope $scope): Response
    {
        abort_unless($request->user()->isCentral() || $request->user()->role === UserRole::SiteAdmin || $request->user()->isClientAdmin(), 403);
        $sites = Site::whereIn('id', $scope->siteIds($request))->with(['client:id,name', 'edgeDevices', 'cameras'])->orderBy('name')->get();

        return Inertia::render('equipment/index', [
            'latestEdgeVersion' => config('vigilia.edge.latest_version'),
            'sites' => $sites->map(fn (Site $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'client' => $site->client?->name,
                'devices' => collect()
                    ->concat($site->edgeDevices->map(fn (EdgeDevice $edge) => $this->row('edge', $edge->id, $edge->name, $edge, [
                        'version' => $edge->version,
                        'update_requested_at' => $edge->update_requested_at?->toIso8601String(),
                        'on_battery_since' => $edge->on_battery_since?->toIso8601String(),
                        'queued_events' => $edge->queued_events,
                        'gaps' => collect($edge->connectivity_gaps ?? [])->reverse()->take(3)->map(fn (array $gap): array => $gap + [
                            'recovered' => DetectionEvent::where('edge_device_id', $edge->id)->whereBetween('started_at', [$gap['from'], $gap['to']])->count(),
                        ])->values(),
                        'discovering' => $edge->discovery_requested_at !== null && $edge->discovery_requested_at->gt(now()->subMinutes(10)),
                        'discovered' => $edge->discovered ?? [],
                        'discovered_at' => $edge->discovered_at?->toIso8601String(),
                        'setups' => CameraSetup::where('edge_device_id', $edge->id)->latest()->limit(10)->get(['id', 'name', 'host', 'status', 'result'])->toArray(),
                    ])))
                    ->concat($site->cameras->sortBy('name')->map(fn (Camera $camera) => $this->row('camera', $camera->id, $camera->name, $camera, ['online' => $camera->is_online])))
                    ->values(),
            ]),
        ]);
    }

    public function update(Request $request, string $type, int $id): RedirectResponse
    {
        $device = $type === 'edge' ? EdgeDevice::findOrFail($id) : Camera::findOrFail($id);
        Gate::authorize('manage', $device->site);

        $device->update($request->validate([
            'model' => ['nullable', 'string', 'max:80'],
            'serial' => ['nullable', 'string', 'max:80'],
            'installed_at' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'date'],
        ]));

        return back();
    }

    /**
     * E6 — the edge picks the request up through its command channel and runs VIGILIA_UPDATE_COMMAND on site.
     */
    public function requestUpdate(Request $request, EdgeDevice $edge): RedirectResponse
    {
        abort_unless($request->user()->isCentral(), 403);
        Gate::authorize('view', $edge->site);
        $edge->update(['update_requested_at' => now()]);

        return back()->with('notice', "Actualización pedida a {$edge->name}. Se aplica en el próximo contacto del equipo.");
    }

    /**
     * K10 — the AI looks at what the camera sees and says what to fix in the installation.
     */
    public function reviewFraming(Camera $camera, CameraViewMonitor $monitor, AiBudget $budget): RedirectResponse
    {
        Gate::authorize('manage', $camera->site);
        $disk = Storage::disk(config('vigilia.media_disk'));
        abort_unless($disk->exists($monitor->latestPath($camera)), 422, 'Todavía no hay una imagen de esta cámara.');
        abort_unless(config('vigilia.ai.enabled') && $budget->consume($camera->site_id), 422, 'La IA no está disponible.');

        try {
            $message = app(Client::class)->messages->create(
                model: config('vigilia.ai.review_model'),
                maxTokens: 800,
                system: 'Sos un instalador experto en cámaras de seguridad. Evaluás si el encuadre de una cámara sirve para detectar personas que entran a una propiedad en Argentina.',
                messages: [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($disk->get($monitor->latestPath($camera)))]],
                    ['type' => 'text', 'text' => "Cámara \"{$camera->name}\"".($camera->sector ? " del sector {$camera->sector}" : '').'. ¿El encuadre es bueno para detectar intrusos? Decí qué mejorar.'],
                ]]],
                outputConfig: ['format' => FramingVerdict::class, 'effort' => 'low'],
            );
            $verdict = $message->parsedOutput();
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('notice', 'La IA no pudo revisar el encuadre ahora. Probá de nuevo en un rato.');
        }

        if ($verdict instanceof FramingVerdict) {
            $camera->update(['framing_review' => ['rating' => $verdict->rating, 'recommendations' => array_slice($verdict->recommendations, 0, 4)], 'framing_reviewed_at' => now()]);
        }

        return back();
    }

    /**
     * K12 — a new camera reported by the edge takes the place of a broken one: the old record keeps its zones,
     * placement, roles and history, and the new view becomes the reference.
     */
    public function replace(Request $request, Camera $camera, CameraViewMonitor $monitor): RedirectResponse
    {
        Gate::authorize('manage', $camera->site);
        $data = $request->validate(['new_camera_id' => ['required', 'integer']]);
        $replacement = Camera::where('site_id', $camera->site_id)->where('id', '!=', $camera->id)->findOrFail($data['new_camera_id']);

        DetectionEvent::where('camera_id', $replacement->id)->update(['camera_id' => $camera->id]);
        $frigateName = $replacement->frigate_name;
        $edgeId = $replacement->edge_device_id;
        $replacement->zones()->delete();
        $replacement->forceDelete();

        $camera->forceFill([
            'frigate_name' => $frigateName, 'edge_device_id' => $edgeId, 'is_online' => true, 'offline_since' => null,
            'installed_at' => now()->toDateString(), 'serial' => null, 'framing_review' => null,
        ])->save();
        $disk = Storage::disk(config('vigilia.media_disk'));
        foreach ($disk->files('references') as $file) {
            if (str_starts_with(basename($file), "{$camera->id}-")) {
                $disk->delete($file);
            }
        }
        $monitor->rebaseline($camera);

        return back()->with('notice', "{$camera->name} quedó reemplazada: conserva sus zonas, su ubicación y su historial.");
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(string $type, int $id, string $name, Camera|EdgeDevice $device, array $extra): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'name' => $name,
            'model' => $device->model,
            'serial' => $device->serial,
            'installed_at' => $device->installed_at?->toDateString(),
            'warranty_until' => $device->warranty_until?->toDateString(),
            'framing_review' => $device instanceof Camera ? $device->framing_review : null,
            'framing_reviewed_at' => $device instanceof Camera ? $device->framing_reviewed_at?->toIso8601String() : null,
        ] + $extra;
    }
}
