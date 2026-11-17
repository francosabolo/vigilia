<?php

namespace App\Http\Controllers;

use App\Enums\DetectionStatus;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * K7 — installation checklist, computed from the real state of the property (nothing to tick by hand), and
 * K9 — the walk test: a few minutes where the AI analyzes everything but alerts nobody.
 */
class SiteSetupController extends Controller
{
    public const TEST_MINUTES = 15;

    public function show(Site $site): Response
    {
        Gate::authorize('manage', $site);

        $site->load(['edgeDevices', 'cameras.zones']);

        return Inertia::render('sites/setup', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'kind' => $site->kind->value],
            'steps' => self::steps($site),
            'testModeUntil' => $site->inTestMode() ? $site->test_mode_until->toIso8601String() : null,
            'testResults' => $this->testResults($site),
        ]);
    }

    public function startTest(Site $site): RedirectResponse
    {
        Gate::authorize('manage', $site);
        $site->update(['test_mode_until' => now()->addMinutes(self::TEST_MINUTES)]);

        return back();
    }

    public function stopTest(Site $site): RedirectResponse
    {
        Gate::authorize('manage', $site);
        $site->update(['test_mode_until' => null]);

        return back();
    }

    /**
     * @return array<int, array{id: string, title: string, detail: string, done: bool, optional: bool, href: string|null}>
     */
    public static function steps(Site $site): array
    {
        $site->loadMissing(['edgeDevices', 'cameras.zones']);
        $cameras = $site->cameras;
        $offlineAfter = now()->subSeconds((int) config('vigilia.edge_offline_after'));
        $online = $site->edgeDevices->contains(fn ($edge) => $edge->last_seen_at?->gt($offlineAfter));
        $tested = DetectionEvent::query()
            ->whereIn('camera_id', $cameras->pluck('id'))
            ->where('is_test', true)
            ->where('status', DetectionStatus::Observed)
            ->where('started_at', '>', now()->subDays(30))
            ->distinct()
            ->pluck('camera_id');
        $untested = $cameras->whereNotIn('id', $tested);
        $unplaced = $cameras->whereNull('lat');
        $noZones = $cameras->filter(fn (Camera $camera) => ! $camera->zones->contains(fn ($zone) => ! empty($zone->coordinates)));
        $guards = User::where('site_id', $site->id)->where('role', UserRole::Guard)->count();
        $base = "/sites/{$site->id}";

        $names = fn ($list) => $list->pluck('name')->take(3)->implode(', ').($list->count() > 3 ? ' y '.($list->count() - 3).' más' : '');

        $steps = [
            ['perimeter', 'Dibujar el perímetro', 'El contorno de la propiedad en el mapa, para medir qué parte ven las cámaras.', count($site->perimeter ?? []) >= 3, false, "{$base}?tab=cobertura"],
            ['edge', 'Registrar el equipo edge', 'Se crea en Ajustes y muestra la clave para configurarlo una sola vez.', $site->edgeDevices->isNotEmpty(), false, "{$base}?tab=ajustes"],
            ['edge_online', 'Conectar el equipo edge', $online ? 'Conectado.' : 'Todavía no se reportó: revisá la red y la clave.', $online, false, "{$base}?tab=ajustes"],
            ['cameras', 'Sumar las cámaras', $cameras->isEmpty() ? 'Aparecen solas cuando el edge las reporta.' : "{$cameras->count()} cámaras reportadas por el edge.", $cameras->isNotEmpty(), false, "{$base}?tab=camaras"],
            ['placed', 'Ubicar cada cámara en el mapa', $unplaced->isEmpty() ? 'Todas ubicadas.' : 'Faltan: '.$names($unplaced).'.', $cameras->isNotEmpty() && $unplaced->isEmpty(), false, "{$base}?tab=cobertura"],
            ['zones', 'Dibujar las zonas vigiladas', $noZones->isEmpty() ? 'Todas las cámaras tienen zonas.' : 'Sin zonas: '.$names($noZones).'.', $cameras->isNotEmpty() && $noZones->isEmpty(), false, "{$base}?tab=camaras"],
            ['test', 'Probar cada cámara caminando frente a ella', $untested->isEmpty() ? 'Todas probadas.' : 'Falta probar: '.$names($untested).'.', $cameras->isNotEmpty() && $untested->isEmpty(), false, "{$base}/setup#prueba"],
            ['guard', 'Dejar quién recibe las alertas', $site->guard_whatsapp || $guards > 0 ? 'La guardia está configurada.' : 'Cargá el WhatsApp de la garita o invitá a la guardia.', (bool) $site->guard_whatsapp || $guards > 0, false, "{$base}?tab=ajustes"],
        ];

        if ($site->kind->hasUnits()) {
            $residents = $site->residents()->count();
            $steps[] = ['residents', 'Invitar a los vecinos', $residents > 0 ? "{$residents} vecinos invitados." : 'Así reciben avisos y tienen el botón de pánico.', $residents > 0, true, "{$base}?tab=vecinos"];
        }

        return array_map(fn (array $step): array => array_combine(['id', 'title', 'detail', 'done', 'optional', 'href'], $step), $steps);
    }

    /**
     * Latest test result per camera: what the detector and the AI saw while the technician walked by.
     *
     * @return array<int, array<string, mixed>>
     */
    private function testResults(Site $site): array
    {
        $events = DetectionEvent::query()
            ->whereIn('camera_id', $site->cameras->pluck('id'))
            ->where('is_test', true)
            ->where('started_at', '>', now()->subDay())
            ->latest('started_at')
            ->get()
            ->groupBy('camera_id');

        return $site->cameras->sortBy('name')->values()->map(function (Camera $camera) use ($events): array {
            $event = $events->get($camera->id)?->first();
            $verdict = $event?->ai_verdict ?? [];

            return [
                'camera_id' => $camera->id,
                'camera' => $camera->name,
                'at' => $event?->started_at->toIso8601String(),
                'result' => match (true) {
                    $event === null => 'pending',
                    ($verdict['skipped'] ?? null) === 'outside_watched_zone' => 'outside',
                    $event->status === DetectionStatus::Observed => 'seen',
                    default => 'pending',
                },
                'detail' => match (true) {
                    $event === null => 'Todavía no pasó nadie frente a esta cámara.',
                    ($verdict['skipped'] ?? null) === 'outside_watched_zone' => 'El detector te vio, pero fuera de las zonas vigiladas: revisá el dibujo de las zonas.',
                    default => ($verdict['description'] ?? 'La IA analizó el paso.').(isset($verdict['confidence']) ? ' ('.round($verdict['confidence'] * 100).'%)' : ''),
                },
                'zones' => $event?->zones ?? [],
            ];
        })->all();
    }
}
