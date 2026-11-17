<?php

namespace App\Jobs;

use App\Enums\Severity;
use App\Events\IncidentChanged;
use App\Models\SnapshotRequest;
use App\Services\Verification\DetectionVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The AI looks at a live frame requested because of a resident report and adds what it sees to the incident.
 */
class ReviewReportedSnapshot implements ShouldQueue
{
    use Queueable;

    public function __construct(public SnapshotRequest $snapshotRequest)
    {
        $this->onQueue('alerts');
    }

    public function handle(DetectionVerifier $verifier): void
    {
        $request = $this->snapshotRequest->load('camera', 'incident');
        $incident = $request->incident;

        if (! $request->snapshot_path || ! $incident->status->isOpen()) {
            return;
        }

        $verdict = $verifier->verifySnapshot(
            $request->snapshot_path,
            $request->camera,
            'Un vecino reportó algo sospechoso en este sector'.($incident->description ? ": \"{$incident->description}\"" : '').'. Revisá si se ve alguna persona o vehículo fuera de lugar.',
        );

        $incident->snapshot_path ??= $request->snapshot_path;

        if ($verdict->isThreat && ($verdict->confidence === null || $verdict->confidence >= config('seguria.ai.threat_threshold'))) {
            $incident->severity = $verdict->objectCount >= 2 ? Severity::Critical : Severity::High;
            $incident->camera_id ??= $request->camera_id;
            $incident->snapshot_path = $request->snapshot_path;
        }

        $incident->save();

        $incident->actions()->create([
            'type' => 'ai_review',
            'channel' => 'ai',
            'notes' => "{$request->camera->name}: {$verdict->description}",
            'meta' => $verdict->toArray() + ['snapshot_request_id' => $request->id],
        ]);

        IncidentChanged::dispatch($incident, 'ai_review');
    }
}
