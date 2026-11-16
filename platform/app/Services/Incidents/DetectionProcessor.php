<?php

namespace App\Services\Incidents;

use App\Enums\DetectionStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\DetectionActivity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Zone;
use App\Services\Activity\ActivityFeed;
use App\Services\Rules\ArmingEvaluator;
use App\Services\Shadow\ShadowMode;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\LabelTranslator;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /** @var array<int, array{step: string, agent: string, result: string, detail: string, at: string}> */
    private array $trace = [];

    public function process(DetectionEvent $event): ?Incident
    {
        if ($event->status !== DetectionStatus::Pending) {
            return $event->incident;
        }

        $event->loadMissing('camera.site', 'camera.zones.armingSchedules');
        $camera = $event->camera;
        $site = $camera->site;
        $this->trace = [];
        $label = LabelTranslator::translate($event->label);

        $this->step('detector', 'Detector del edge', 'ok', sprintf('Vio una %s en %s%s.', $label, $camera->name, $event->score !== null ? ' (seguridad '.round($event->score * 100).'%)' : ''));

        $zones = $this->matchingZones($event);

        if ($zones === false) {
            $this->step('zone', 'Reglas del barrio', 'stop', $event->zones ? 'Estaba fuera de las zonas vigiladas de esta cámara, o no es un tipo de objeto que se vigile ahí.' : 'No entró en ninguna zona vigilada.');

            return $this->skip($event, 'outside_watched_zone');
        }

        $this->step('zone', 'Reglas del barrio', 'ok', $zones === null ? 'La cámara no tiene zonas: se vigila la imagen completa.' : 'Entró en '.collect($zones)->map(fn (Zone $zone) => "{$zone->name} ({$zone->kind->label()})")->implode(', ').'.');

        $zone = $zones === null
            ? null
            : collect($zones)->first(fn (Zone $zone): bool => $this->arming->isArmed($zone, $event->started_at, $site->timezone));

        if ($zones !== null && $zone === null) {
            $this->step('schedule', 'Reglas del barrio', 'stop', 'La zona no está armada a esta hora: no hace falta mirarla.');

            return $this->skip($event, 'zone_disarmed');
        }

        $this->step('schedule', 'Reglas del barrio', 'ok', $zone && $zone->armingSchedules->isNotEmpty() ? 'La zona está armada a esta hora.' : 'La zona está armada las 24 h.');

        $this->broadcast($event, analyzing: true);
        $verdict = $this->verifier->verify($event);
        $event->ai_verdict = $verdict->toArray();
        $agent = app(ActivityFeed::class)->agentName($verdict->model);
        $confidence = ($verdict->confidence !== null ? ' Confianza '.round($verdict->confidence * 100).'%.' : '')
            .($event->hasSequence() ? ' Miró '.count($event->frame_paths).' cuadros del clip.' : '');

        if ($verdict->model && $verdict->model === config('seguria.ai.review_model')) {
            $this->step('ai', app(ActivityFeed::class)->agentName(config('seguria.ai.model')), 'doubt', 'No estaba segura: le pidió una segunda opinión a la revisora.');
        }

        if (! $this->isThreat($verdict)) {
            $this->step('ai', $agent, 'stop', "Descartó: {$verdict->description}{$confidence}");
            $event->status = DetectionStatus::Dismissed;
            $event->trace = $this->trace;
            $event->save();
            $this->broadcast($event);
            app(ShadowMode::class)->consider($event);

            return null;
        }

        $this->step('ai', $agent, 'alert', "Es una amenaza: {$verdict->description}{$confidence}");

        $incident = DB::transaction(function () use ($event, $zone, $verdict): Incident {
            $incident = $this->findOpenIncident($event, $zone);

            if ($incident) {
                $this->step('decision', 'Seguria', 'alert', "Lo sumó al incidente #{$incident->id}, abierto hace minutos en la misma zona.");
                $this->attach($incident, $event, $verdict);
                IncidentChanged::dispatch($incident, 'detection_added');

                return $incident;
            }

            $incident = $this->open($event, $zone, $verdict);
            $this->step('decision', 'Seguria', 'alert', "Abrió el incidente #{$incident->id} con severidad {$incident->severity->value} y avisó a la guardia.");
            IncidentOpened::dispatch($incident);
            IncidentChanged::dispatch($incident, 'opened');

            return $incident;
        });

        $event->forceFill(['trace' => $this->trace])->save();
        $this->broadcast($event);
        app(ShadowMode::class)->consider($event);

        return $incident;
    }

    private function step(string $step, string $agent, string $result, string $detail): void
    {
        $this->trace[] = ['step' => $step, 'agent' => $agent, 'result' => $result, 'detail' => $detail, 'at' => now()->toIso8601String()];
    }

    private function broadcast(DetectionEvent $event, bool $analyzing = false): void
    {
        $feed = app(ActivityFeed::class);
        $item = $analyzing ? $feed->analyzing($event) : $feed->item($event);

        // The live feed is a nicety: if the websocket server is down, the detection must still be processed.
        try {
            DetectionActivity::dispatch($event->camera->site->organization_id, $event->camera->site_id, $item);
        } catch (\Throwable $exception) {
            Log::warning('Activity broadcast failed', ['detection_event_id' => $event->id, 'error' => $exception->getMessage()]);
        }
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
            'severity' => $this->severity($event, $verdict, $zone),
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

        $severity = $this->severity($event, $verdict, $incident->zone);
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

    private function severity(DetectionEvent $event, VerificationResult $verdict, ?Zone $zone = null): Severity
    {
        if ($event->label === 'person' && $zone?->kind?->isAccessPoint()) {
            return Severity::Critical;
        }

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
        $event->forceFill(['status' => DetectionStatus::Ignored, 'ai_verdict' => ['skipped' => $reason], 'trace' => $this->trace])->save();
        $this->broadcast($event);

        return null;
    }
}
