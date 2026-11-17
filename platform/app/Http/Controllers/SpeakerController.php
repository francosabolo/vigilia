<?php

namespace App\Http\Controllers;

use App\Events\IncidentChanged;
use App\Models\Camera;
use App\Models\DeterrenceCommand;
use App\Models\Incident;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The camera's speaker: B7 the operator talks to the person (a message read on the speaker) and K11 the technician
 * tests it. Both travel through the edge's command channel and run SEGURIA_DETER_COMMAND on site.
 */
class SpeakerController extends Controller
{
    public function speak(Request $request, Incident $incident): RedirectResponse
    {
        Gate::authorize('act', $incident);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:200'],
            'camera_id' => ['nullable', 'integer'],
        ]);

        $camera = $data['camera_id'] ?? null
            ? Camera::where('site_id', $incident->site_id)->findOrFail($data['camera_id'])
            : $incident->camera;
        abort_unless($camera?->edge_device_id, 422, 'Esa cámara no tiene un equipo edge que reproduzca el mensaje.');

        DeterrenceCommand::create([
            'incident_id' => $incident->id, 'edge_device_id' => $camera->edge_device_id, 'camera_id' => $camera->id,
            'message' => $data['message'], 'status' => 'pending', 'requested_by' => $request->user()->id,
        ]);
        $incident->actions()->create([
            'type' => 'speaker_message', 'user_id' => $request->user()->id, 'channel' => 'web',
            'notes' => "Por el altavoz de {$camera->name}: «{$data['message']}»",
        ]);
        IncidentChanged::dispatch($incident, 'speaker');

        return back()->with('notice', 'Mensaje enviado al altavoz. El resultado aparece en la bitácora.');
    }

    public function test(Request $request, Camera $camera): RedirectResponse
    {
        Gate::authorize('manage', $camera->site);
        abort_unless($camera->edge_device_id, 422);

        DeterrenceCommand::create([
            'edge_device_id' => $camera->edge_device_id, 'camera_id' => $camera->id,
            'message' => 'Prueba del altavoz de Vigilia.', 'status' => 'pending', 'requested_by' => $request->user()->id,
        ]);

        return back()->with('notice', "Prueba enviada al altavoz de {$camera->name}.");
    }
}
