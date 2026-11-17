<?php

namespace App\Services\Sites;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The site the central staff is currently looking at (header switcher). Null means "all sites".
 */
class SiteScope
{
    private const SESSION_KEY = 'current_site_id';

    public function current(Request $request): ?Site
    {
        $user = $request->user();
        $accessible = $user->accessibleSiteIds();

        if (! $user->canSwitchSites()) {
            return $user->site;
        }

        $id = $request->session()->get(self::SESSION_KEY);

        return $id && in_array($id, $accessible, true) ? Site::find($id) : null;
    }

    public function set(Request $request, ?int $siteId): void
    {
        abort_if($siteId !== null && ! in_array($siteId, $request->user()->accessibleSiteIds(), true), 403);

        $request->session()->put(self::SESSION_KEY, $siteId);
    }

    /**
     * Site ids the current view is limited to.
     *
     * @return array<int, int>
     */
    public function siteIds(Request $request): array
    {
        $current = $this->current($request);

        return $current ? [$current->id] : $request->user()->accessibleSiteIds();
    }

    /**
     * @return array{sites: array<int, array{id: int, name: string}>, current: array{id: int, name: string}|null, canSwitch: bool, alerts: array{open: int, escalated: int}}|null
     */
    public function share(Request $request): ?array
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || $user->isResident()) {
            return null;
        }

        $current = $this->current($request);
        $open = Incident::query()->whereIn('site_id', $this->siteIds($request))->where('status', '!=', IncidentStatus::Resolved);

        return [
            'sites' => Site::whereIn('id', $user->accessibleSiteIds())->orderBy('is_simulated')->orderBy('name')->get(['id', 'name', 'kind', 'is_simulated'])->toArray(),
            'current' => $current ? ['id' => $current->id, 'name' => $current->name, 'kind' => $current->kind->value] : null,
            'canSwitch' => $user->canSwitchSites(),
            'alerts' => [
                'open' => (clone $open)->count(),
                'escalated' => (clone $open)->where('status', IncidentStatus::Escalated)->count(),
                // With one property selected, what is going on in the rest: the live alerts cover every property.
                'elsewhere' => $current
                    ? Incident::whereIn('site_id', array_diff($user->accessibleSiteIds(), [$current->id]))->where('status', '!=', IncidentStatus::Resolved)->count()
                    : 0,
            ],
        ];
    }
}
