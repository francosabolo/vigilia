<?php

namespace App\Services\Incidents;

use App\Enums\IncidentResolution;
use App\Enums\IncidentStatus;
use App\Events\IncidentChanged;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\User;

/**
 * State transitions of an incident. Every transition is audited in incident_actions and broadcast.
 */
class IncidentWorkflow
{
    public function markNotified(Incident $incident, array $channels): void
    {
        $incident->forceFill([
            'notified_at' => now(),
            'status' => $incident->status === IncidentStatus::New ? IncidentStatus::Notified : $incident->status,
        ])->save();

        $this->record($incident, 'notified', null, null, null, ['channels' => $channels]);
    }

    public function acknowledge(Incident $incident, ?User $user, string $channel): bool
    {
        if (! $incident->status->isOpen() || $incident->acknowledged_at !== null) {
            return false;
        }

        $incident->forceFill([
            'status' => IncidentStatus::Acknowledged,
            'acknowledged_at' => now(),
            'acknowledged_by' => $user?->id,
        ])->save();

        $this->record($incident, 'acknowledged', $user, $channel);

        return true;
    }

    public function escalate(Incident $incident): bool
    {
        if (! $incident->status->isOpen() || $incident->acknowledged_at !== null || $incident->escalated_at !== null) {
            return false;
        }

        $incident->forceFill(['status' => IncidentStatus::Escalated, 'escalated_at' => now()])->save();

        $this->record($incident, 'escalated', null, 'system', 'Sin respuesta de la guardia: escalado a la central.');

        return true;
    }

    public function resolve(Incident $incident, IncidentResolution $resolution, ?User $user, string $channel, ?string $notes = null): bool
    {
        if (! $incident->status->isOpen()) {
            return false;
        }

        $incident->forceFill([
            'status' => IncidentStatus::Resolved,
            'resolution' => $resolution,
            'resolved_at' => now(),
            'acknowledged_at' => $incident->acknowledged_at ?? now(),
            'acknowledged_by' => $incident->acknowledged_by ?? $user?->id,
        ])->save();

        $this->record($incident, $resolution->value, $user, $channel, $notes);

        // A closed security incident tells what its detections really were (shadow mode and evaluations use it).
        if (in_array($resolution, [IncidentResolution::Confirmed, IncidentResolution::FalseAlarm], true)) {
            DetectionEvent::where('incident_id', $incident->id)
                ->where(fn ($query) => $query->whereNull('ground_truth_source')->orWhere('ground_truth_source', 'incident'))
                ->update(['ground_truth' => $resolution === IncidentResolution::Confirmed, 'ground_truth_source' => 'incident']);
        }

        return true;
    }

    public function addNote(Incident $incident, User $user, string $notes): void
    {
        $this->record($incident, 'note', $user, 'web', $notes);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function record(Incident $incident, string $type, ?User $user, ?string $channel, ?string $notes = null, ?array $meta = null): void
    {
        $incident->actions()->create([
            'type' => $type,
            'user_id' => $user?->id,
            'channel' => $channel,
            'notes' => $notes,
            'meta' => $meta,
        ]);

        IncidentChanged::dispatch($incident, $type);
    }
}
