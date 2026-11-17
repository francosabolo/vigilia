<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ImpersonationController;
use App\Services\Sites\SiteScope;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'retentionDays' => config('vigilia.retention_days'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'siteContext' => fn () => app(SiteScope::class)->share($request),
            'impersonating' => fn () => $request->session()->has(ImpersonationController::SESSION_KEY) && $request->user()
                ? ['name' => $request->user()->name, 'role' => $request->user()->role->value]
                : null,
            // M24 — the AI stopped answering: alerts keep going out, unfiltered; the console says so.
            'aiUnavailableSince' => fn () => $request->user() && ! $request->user()->isResident() && Cache::has('ai-unavailable-until')
                ? Cache::get('ai-unavailable-since')
                : null,
            // H3 — the security company's own brand over the console and the residents' app.
            'brand' => function () use ($request): ?array {
                $organization = $request->user()?->organization;

                return $organization?->brand_name || $organization?->brand_color
                    ? ['name' => $organization->brand_name, 'color' => $organization->brand_color]
                    : null;
            },
            'unreadNotifications' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'flash' => fn () => ['notice' => $request->session()->get('notice')],
        ]);
    }
}
