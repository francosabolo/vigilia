<?php

namespace App\Http\Controllers;

use App\Models\ResidentMessage;
use App\Models\Site;
use App\Services\Residents\ResidentDevices;
use App\Services\Sites\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L11 — residents one click away for the guard: the same panel as the property's tab (search by name, unit or
 * plate, the houses on the map, call, WhatsApp, notify), for the property picked in the header.
 */
class ResidentDirectoryController extends Controller
{
    public function __invoke(Request $request, SiteScope $scope, ResidentDevices $devices, SiteController $sites): Response
    {
        $ids = $scope->siteIds($request);
        $site = $scope->current($request) ?? (count($ids) === 1 ? Site::find($ids[0]) : null);

        if (! $site) {
            return Inertia::render('residents/directory', ['site' => null, 'sites' => Site::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])]);
        }

        Gate::authorize('view', $site);

        return Inertia::render('residents/directory', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'kind' => $site->kind->value,
                'perimeter' => $site->perimeter ?? [],
                'center' => $site->lat !== null ? [$site->lat, $site->lng] : null,
            ],
            'residents' => $sites->residents($site, $devices),
            'sectors' => $site->cameras()->pluck('sector')->filter()->unique()->sort()->values(),
            'messages' => ResidentMessage::where('site_id', $site->id)->with('sender:id,name')->latest()->limit(5)->get()->map(fn (ResidentMessage $message): array => [
                'id' => $message->id, 'body' => $message->body, 'recipients' => $message->recipients, 'urgent' => $message->urgent,
                'sender' => $message->sender?->name, 'at' => $message->created_at->toIso8601String(),
            ]),
            'canManage' => Gate::allows('manage', $site),
        ]);
    }
}
