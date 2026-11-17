<?php

namespace App\Http\Controllers;

use App\Models\AccessAuthorization;
use App\Models\AccessEntry;
use App\Services\Access\AccessDesk;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * D1/D2 from the resident's app: invite a visit (a code and a QR to send by WhatsApp), authorize a provider or
 * domestic staff, and answer when someone without notice is at the gate.
 */
class ResidentVisitController extends Controller
{
    public function store(Request $request, AccessDesk $desk): RedirectResponse
    {
        $resident = $request->user();
        $data = AccessController::validatedAuthorization($request, $resident->site, resident: true);
        $authorization = $desk->authorize($resident->site, $data, $resident, $resident);

        return back()->with('notice', "Listo. El código para la guardia es {$authorization->code}.");
    }

    public function destroy(Request $request, AccessAuthorization $authorization): RedirectResponse
    {
        abort_unless($authorization->resident_id === $request->user()->id, 404);
        $authorization->update(['revoked_at' => now()]);

        return back();
    }

    public function qr(Request $request, AccessAuthorization $authorization): Response
    {
        abort_unless($authorization->resident_id === $request->user()->id, 404);

        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 1), new SvgImageBackEnd)))->writeString($authorization->token);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=600']);
    }

    public function answer(Request $request, AccessEntry $accessEntry, AccessDesk $desk): RedirectResponse
    {
        abort_unless($accessEntry->resident_id === $request->user()->id, 404);
        $data = $request->validate(['allow' => ['required', 'boolean']]);

        $desk->answer($accessEntry, $data['allow'], $request->user(), $data['allow'] ? 'Lo autorizó el vecino desde la app.' : null);

        return back();
    }
}
