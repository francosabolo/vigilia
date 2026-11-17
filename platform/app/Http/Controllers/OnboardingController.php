<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\EdgeDevice;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * K15 — guided setup of a property from zero to watched, one step per screen: the property, where it is, the edge
 * box, the cameras, who receives the alerts, the residents and the walk test. Every step shows its real state.
 */
class OnboardingController extends Controller
{
    public const STEPS = ['propiedad', 'ubicacion', 'equipo', 'camaras', 'guardia', 'vecinos', 'prueba'];

    public function start(Request $request): Response
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);

        return Inertia::render('onboarding/show', [
            'step' => 'propiedad',
            'steps' => self::STEPS,
            'site' => null,
            'clients' => Client::where('organization_id', $request->user()->organization_id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Site $site, ?string $step = null): Response
    {
        Gate::authorize('manage', $site);
        $step = in_array($step, self::STEPS, true) ? $step : 'ubicacion';
        $site->load(['edgeDevices', 'cameras.zones']);
        $offlineAfter = now()->subSeconds((int) config('vigilia.edge_offline_after'));

        return Inertia::render('onboarding/show', [
            'step' => $step,
            'steps' => self::STEPS,
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'kind' => $site->kind->value,
                'full_address' => $site->fullAddress(),
                'perimeter' => $site->perimeter ?? [],
                'center' => $site->lat !== null ? [$site->lat, $site->lng] : null,
                'guard_whatsapp' => $site->guard_whatsapp,
                'ack_timeout_seconds' => $site->ack_timeout_seconds,
                'edges' => $site->edgeDevices->map(fn (EdgeDevice $edge): array => [
                    'id' => $edge->id,
                    'name' => $edge->name,
                    'online' => $edge->last_seen_at?->gt($offlineAfter) ?? false,
                    'last_seen_at' => $edge->last_seen_at?->toIso8601String(),
                ]),
                'cameras' => $site->cameras->sortBy('name')->values()->map(fn ($camera): array => [
                    'id' => $camera->id,
                    'name' => $camera->name,
                    'is_online' => $camera->is_online,
                    'placed' => $camera->lat !== null,
                    'zones' => $camera->zones->filter(fn ($zone) => ! empty($zone->coordinates))->count(),
                    'is_access' => $camera->is_access,
                    'is_guard_post' => $camera->is_guard_post,
                    'is_checkpoint' => $camera->is_checkpoint,
                ]),
            ],
            'checklist' => SiteSetupController::steps($site),
            'newEdge' => $request->session()->get('new_edge'),
            'edgeServerUrl' => url('/'),
            'guards' => User::where('site_id', $site->id)->where('role', UserRole::Guard)->get(['id', 'name', 'email', 'phone']),
            'residentsCount' => $site->residents()->count(),
            'canManageEdges' => $request->user()->isCentral(),
            'isAdmin' => $request->user()->role === UserRole::Admin,
            'clients' => [],
        ]);
    }
}
