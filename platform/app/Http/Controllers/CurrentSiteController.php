<?php

namespace App\Http\Controllers;

use App\Services\Sites\SiteScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CurrentSiteController extends Controller
{
    public function __invoke(Request $request, SiteScope $scope): RedirectResponse
    {
        $data = $request->validate(['site_id' => ['nullable', 'integer']]);
        $scope->set($request, $data['site_id'] ?? null);

        return back();
    }
}
