<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Client;
use App\Services\Reports\BusinessReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * H4–H6 for the owner of the security company: billing per client, SLA compliance and margin per property.
 */
class BusinessController extends Controller
{
    public function index(Request $request, BusinessReport $report): Response
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $organization = $request->user()->organization;
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $from = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();

        return Inertia::render('business/index', [
            'month' => $month,
            'organization' => [
                'currency' => $organization->currency,
                'price_per_camera' => $organization->price_per_camera !== null ? (float) $organization->price_per_camera : null,
                'operator_hour_cost' => $organization->operator_hour_cost !== null ? (float) $organization->operator_hour_cost : null,
                'usd_rate' => $organization->usd_rate !== null ? (float) $organization->usd_rate : null,
                'brand_name' => $organization->brand_name,
                'brand_color' => $organization->brand_color,
            ],
            'report' => $report->build($organization, $from, $from->endOfMonth()),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);

        $request->user()->organization->update($request->validate([
            'price_per_camera' => ['nullable', 'numeric', 'min:0'],
            'operator_hour_cost' => ['nullable', 'numeric', 'min:0'],
            'usd_rate' => ['nullable', 'numeric', 'min:0'],
            'brand_name' => ['nullable', 'string', 'max:60'],
            'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]));

        return back();
    }

    public function updateClient(Request $request, Client $client): RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin && $client->organization_id === $request->user()->organization_id, 403);

        $client->update($request->validate([
            'price_per_camera' => ['nullable', 'numeric', 'min:0'],
            'sla_ack_seconds' => ['nullable', 'integer', 'between:30,3600'],
        ]));

        return back();
    }
}
