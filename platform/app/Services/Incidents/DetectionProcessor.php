<?php

namespace App\Services\Incidents;

use App\Enums\DetectionStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Zone;
use App\Services\Rules\ArmingEvaluator;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\LabelTranslator;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\DB;

/**
 * Turns a raw edge detection into an incident: zone match → armed schedule → AI verification → dedup.
 */
class DetectionProcessor
{
    private const VEHICLE_LABELS = ['car', 'motorcycle', 'truck', 'bicycle'];

    public function __construct(
        private readonly ArmingEvaluator $arming,
        private readonly DetectionVerifier $verifier,
    ) {}

    public function process(DetectionEvent $event): ?Incident
    {
        if ($event->status !== DetectionStatus::Pending) {
            return $event->incident;
        }

        $event->loadMissing('camera.site', 'camera.zones.armingSchedules');
        $camera = $event->camera;
        $site = $camera->site;

        $zones = $this->matchingZones($event);

        if ($zones === false) {
            return $this->skip($event, 'outside_watched_zone');
        }

        $zone = $zones === null
            ? null
            : collect($zones)->first(fn (Zone $zone): bool => $this->arming->isArmed($zone, $event->started_at, $site->timezone));

        if ($zones !== null && $zone === null) {
            return $this->skip($event, 'zone_disarmed');
        }

        $verdict = $this->verifier->verify($event);
        $event->ai_verdict = $verdict->toArray();

        if (! $this->isThreat($verdict)) {
            $event->status = DetectionStatus::Dismissed;
            $event->save();

            return null;
        }

        return DB::transaction(function () use ($event, $zone, $verdict): Incident {
            $incident = $this->findOpenIncident($event, $zone);

            if ($incident) {
                $this->attach($incident, $event, $verdict);
                IncidentChanged::dispatch($incident, 'detection_added');

                return $incident;
            }

            $incident = $this->open($event, $zone, $verdict);
            IncidentOpened::dispatch($incident);
            IncidentChanged::dispatch($incident, 'opened');

            return $incident;
        });
    }

    /**
     * @return array<int, Zone>|false|null null when the camera has no zones (whole frame watched), false when no zone matches.
     */
    private function matchingZones(DetectionEvent $event): array|false|null
    {
        $zones = $event->camera->zones;

        if ($zones->isEmpty()) {
            return null;
        }

        $matching = $zones->filter(fn (Zone $zone): bool => in_array($zone->frigate_zone, $event->zones ?? [], true)
            && (empty($zone->watched_labels) || in_array($event->label, $zone->watched_labels, true)));

        return $matching->isEmpty() ? false : $matching->values()->all();
    }

    private function isThreat(VerificationResult $verdict): bool
    {
        if (! $verdict->isThreat) {
            return false;
        }

        return $verdict->confidence === null || $verdict->confidence >= config('seguria.ai.threat_threshold');
    }

    private function findOpenIncident(DetectionEvent $event, ?Zone $zone): ?Incident
    {
        $since = $event->started_at->copy()->subSeconds(config('seguria.dedup_seconds'));

        return Incident::query()
            ->where('camera_id', $event->camera_id)
            ->where('zone_id', $zone?->id)
            ->where('status', '!=', IncidentStatus::Resolved)
            ->whereHas('detectionEvents', fn ($query) => $query->where('started_at', '>=', $since))
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    private function open(DetectionEvent $event, ?Zone $zone, VerificationResult $verdict): Incident
    {
        $label = ucfirst(LabelTranslator::translate($event->label));
        $place = $zone ? "{$zone->name} ({$event->camera->name})" : $event->camera->name;

        $incident = Incident::create([
            'site_id' => $event->camera->site_id,
            'camera_id' => $event->camera_id,
            'zone_id' => $zone?->id,
            'sector' => $event->camera->sector,
            'severity' => $this->severity($event, $verdict),
            'status' => IncidentStatus::New,
            'title' => "{$label} en {$place}",
            'description' => $verdict->description,
            'ai_confidence' => $verdict->confidence,
            'snapshot_path' => $event->snapshot_path,
            'clip_path' => $event->clip_path,
            'detected_at' => $event->started_at,
        ]);

        $event->forceFill(['status' => DetectionStatus::Threat, 'incident_id' => $incident->id])->save();

        $incident->actions()->create([
            'type' => 'detected',
            'channel' => 'ai',
            'notes' => $verdict->description,
            'meta' => $verdict->toArray() + ['detection_event_id' => $event->id],
        ]);

        return $incident;
    }

    private function attach(Incident $incident, DetectionEvent $event, VerificationResult $verdict): void
    {
        $event->forceFill(['status' => DetectionStatus::Threat, 'incident_id' => $incident->id])->save();

        $severity = $this->severity($event, $verdict);
        if ($this->rank($severity) > $this->rank($incident->severity)) {
            $incident->severity = $severity;
        }

        $incident->fill([
            'description' => $verdict->description,
            'snapshot_path' => $event->snapshot_path ?? $incident->snapshot_path,
            'clip_path' => $event->clip_path ?? $incident->clip_path,
        ])->save();

        $incident->actions()->create([
            'type' => 'detection_added',
            'channel' => 'ai',
            'notes' => $verdict->description,
            'meta' => ['detection_event_id' => $event->id],
        ]);
    }

    private function severity(DetectionEvent $event, VerificationResult $verdict): Severity
    {
        if ($event->label === 'person') {
            return $verdict->objectCount >= 2 ? Severity::Critical : Severity::High;
        }

        return in_array($event->label, self::VEHICLE_LABELS, true) ? Severity::Medium : Severity::Low;
    }

    private function rank(Severity $severity): int
    {
        return array_search($severity, Severity::cases(), true);
    }

    private function skip(DetectionEvent $event, string $reason): null
    {
        $event->forceFill(['status' => DetectionStatus::Ignored, 'ai_verdict' => ['skipped' => $reason]])->save();

        return null;
    }
}
