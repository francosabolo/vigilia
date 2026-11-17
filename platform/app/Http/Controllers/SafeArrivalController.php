<?php

namespace App\Http\Controllers;

use App\Models\SafeArrival;
use App\Services\Residents\SafeArrivals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SafeArrivalController extends Controller
{
    public function start(Request $request, SafeArrivals $arrivals): RedirectResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'in:5,10,15,20,30']]);
        $arrivals->start($request->user(), $data['minutes']);

        return back()->with('notice', 'Le avisamos a la guardia. Cuando entres, tocá «Ya entré».');
    }

    public function arrivedOwn(Request $request, SafeArrival $arrival, SafeArrivals $arrivals): RedirectResponse
    {
        abort_unless($arrival->user_id === $request->user()->id, 404);
        $arrivals->arrived($arrival);

        return back();
    }

    /**
     * The guard saw them come in.
     */
    public function arrived(SafeArrival $arrival, SafeArrivals $arrivals): RedirectResponse
    {
        Gate::authorize('view', $arrival->site);
        $arrivals->arrived($arrival);

        return back();
    }
}
