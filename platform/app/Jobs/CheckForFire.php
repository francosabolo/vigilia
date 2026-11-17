<?php

namespace App\Jobs;

use Anthropic\Client;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\Incident;
use App\Services\Cameras\FireVerdict;
use App\Services\Verification\AiBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * M13 — smoke or fire in a machine room, a garage or a house, seen live in the camera's fresh frame and raised as an
 * emergency with the fire brigade's number.
 */
class CheckForFire implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Camera $camera, public string $image) {}

    public function handle(AiBudget $budget): void
    {
        $camera = $this->camera;
        $open = Incident::where('camera_id', $camera->id)->where('status', '!=', IncidentStatus::Resolved)->where('title', 'like', 'Posible humo o fuego%')->exists();
        if ($open || ! config('seguria.ai.enabled') || ! $budget->consume($camera->site_id)) {
            return;
        }

        try {
            $verdict = app(Client::class)->messages->create(
                model: config('seguria.ai.review_model'),
                maxTokens: 300,
                system: 'Revisás cuadros de cámaras de seguridad buscando humo o fuego. Es mejor avisar de más que de menos, pero el vapor, la niebla, el polvo, los reflejos y las luces rojas no son fuego.',
                messages: [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => $this->image]],
                    ['type' => 'text', 'text' => "Cámara \"{$camera->name}\" de {$camera->site->name}. ¿Hay humo o fuego?"],
                ]]],
                outputConfig: ['format' => FireVerdict::class, 'effort' => 'low'],
            )->parsedOutput();
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if (! $verdict instanceof FireVerdict || ! $verdict->fire || $verdict->confidence < 0.5) {
            return;
        }

        $path = "sites/{$camera->site_id}/fire/camera-{$camera->id}-".now()->format('YmdHis').'.jpg';
        Storage::disk(config('seguria.media_disk'))->put($path, base64_decode($this->image));
        $incident = Incident::create([
            'site_id' => $camera->site_id,
            'camera_id' => $camera->id,
            'source' => IncidentSource::Detection,
            'sector' => $camera->sector,
            'severity' => Severity::Critical,
            'status' => IncidentStatus::New,
            'title' => "Posible humo o fuego ({$camera->name})",
            'description' => trim($verdict->description).' Si se confirma, llamar a Bomberos al 100 y evacuar el sector.',
            'snapshot_path' => $path,
            'detected_at' => now(),
        ]);
        $incident->actions()->create(['type' => 'ai_fire', 'channel' => 'ai', 'notes' => $verdict->description, 'meta' => ['confidence' => $verdict->confidence]]);

        IncidentOpened::dispatch($incident);
        IncidentChanged::dispatch($incident, 'opened');
    }
}
