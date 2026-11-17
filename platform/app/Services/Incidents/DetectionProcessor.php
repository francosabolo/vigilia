<?php

namespace App\Services\Incidents;

use App\Enums\DetectionStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\ZoneAutonomy;
use App\Enums\ZoneKind;
use App\Events\DetectionActivity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Zone;
use App\Services\Activity\ActivityFeed;
use App\Services\Autonomy\AutonomyPolicy;
use App\Services\Detection\NoisyCameras;
use App\Services\Guards\GuardWatch;
use App\Services\Rules\ArmingEvaluator;
use App\Services\Shadow\ShadowMode;
use App\Services\Verification\DetectionVerifier;
use App\Services\Verification\LabelTranslator;
use App\Services\Verification\VerificationResult;
use App\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a raw edge detection into an incident: zone match → armed schedule → AI verification → dedup.
 */
class DetectionProcessor
{
    private const VEHICLE_LABELS = ['car', 'motorcycle', 'truck', 'bicycle'];

    private ?int $stoppedMinutes = null;

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
        $testing = $site->inTestMode();
        if ($testing) {
            $event->is_test = true;
        }

        if ($zones === false) {
            $this->step('zone', 'Reglas del barrio', 'stop', $event->zones ? 'Estaba fuera de las zonas vigiladas de esta cámara, o no es un tipo de objeto que se vigile ahí.' : 'No entró en ninguna zona vigilada.');

            return $this->skip($event, 'outside_watched_zone');
        }

        $this->step('zone', 'Reglas del barrio', 'ok', $zones === null ? 'La cámara no tiene zonas: se vigila la imagen completa.' : 'Entró en '.collect($zones)->map(fn (Zone $zone) => "{$zone->name} ({$zone->kind->label()})")->implode(', ').'.');

        $zone = match (true) {
            $zones === null => null,
            // Installation test: the technician walks by at any hour, so the schedule does not apply.
            $testing => collect($zones)->first(),
            default => collect($zones)->first(fn (Zone $zone): bool => $this->arming->isArmed($zone, $event->started_at, $site->timezone)),
        };

        if ($zones !== null && $zone === null) {
            $this->step('schedule', 'Reglas del barrio', 'stop', 'La zona no está armada a esta hora: no hace falta mirarla.');

            return $this->skip($event, 'zone_disarmed');
        }

        $this->step('schedule', 'Reglas del barrio', 'ok', match (true) {
            $testing => 'Prueba de instalación: se ignora el horario.',
            $zone && $zone->armingSchedules->isNotEmpty() => 'La zona está armada a esta hora.',
            default => 'La zona está armada las 24 h.',
        });

        // M1/M2 — a noisy camera (storm, spider web, headlights): group with what the AI just dismissed there.
        if (! $testing && ($previous = app(NoisyCameras::class)->groupWith($event, $zone))) {
            $seconds = (int) $previous->started_at->diffInSeconds($event->started_at, absolute: true);
            $this->step('ai', 'Vigilia', 'stop', "Cámara ruidosa: se agrupó con la detección de hace {$seconds} s, que la IA descartó. No se volvió a pagar el análisis.");
            $event->forceFill([
                'status' => DetectionStatus::Dismissed,
                'ai_verdict' => ['grouped_with' => $previous->id, 'description' => $previous->ai_verdict['description'] ?? 'Agrupada con la anterior.'],
                'trace' => $this->trace,
            ])->save();
            $this->broadcast($event);

            return null;
        }

        $this->broadcast($event, analyzing: true);
        $verdict = $this->verifier->verify($event);
        $event->ai_verdict = $verdict->toArray();
        $agent = app(ActivityFeed::class)->agentName($verdict->model);
        $confidence = ($verdict->confidence !== null ? ' Confianza '.round($verdict->confidence * 100).'%.' : '')
            .($event->hasSequence() ? ' Miró '.count($event->frame_paths).' cuadros del clip.' : '');

        if ($verdict->model && $verdict->model === config('vigilia.ai.review_model')) {
            $this->step('ai', app(ActivityFeed::class)->agentName(config('vigilia.ai.model')), 'doubt', 'No estaba segura: le pidió una segunda opinión a la revisora.');
        }

        if ($testing) {
            $this->step('ai', $agent, $this->isThreat($verdict) ? 'alert' : 'stop', "{$verdict->description}{$confidence}");
            $this->step('decision', 'Vigilia', 'stop', 'Prueba de instalación: quedó registrado y no se avisó a nadie.');
            $event->forceFill(['status' => DetectionStatus::Observed, 'trace' => $this->trace])->save();
            $this->broadcast($event);

            return null;
        }

        // A6 — a vehicle parked by the perimeter for a long time is worth a look even with nobody in sight.
        $this->stoppedMinutes = $this->stoppedVehicleMinutes($event);
        if (! $this->isThreat($verdict) && $this->stoppedMinutes !== null) {
            $this->step('ai', $agent, 'doubt', "No ve una amenaza, pero el vehículo lleva {$this->stoppedMinutes} minutos detenido ahí: {$verdict->description}");
        }

        if (! $this->isThreat($verdict) && $this->stoppedMinutes === null) {
            $this->step('ai', $agent, 'stop', "Descartó: {$verdict->description}{$confidence}");
            $event->status = DetectionStatus::Dismissed;
            $event->trace = $this->trace;
            $event->save();
            app(GuardWatch::class)->recordFromDetection($event);
            $this->broadcast($event);
            app(ShadowMode::class)->consider($event);

            return null;
        }

        if ($this->isThreat($verdict)) {
            $this->step('ai', $agent, 'alert', "Es una amenaza: {$verdict->description}{$confidence}");
        }
        if (self::isRiskyAction($verdict->description)) {
            $this->step('decision', 'Vigilia', 'alert', 'La IA describe que alguien trepa, corta o fuerza el acceso: alerta crítica.');
        }

        if ($zone?->autonomy === ZoneAutonomy::Calibration) {
            $this->step('decision', 'Vigilia', 'stop', "La zona {$zone->name} está en calibración: quedó registrado y no se avisó a nadie.");
            $event->forceFill(['status' => DetectionStatus::Observed, 'trace' => $this->trace])->save();
            $this->broadcast($event);
            app(ShadowMode::class)->consider($event);

            return null;
        }

        $incident = DB::transaction(function () use ($event, $zone, $verdict): Incident {
            $incident = $this->findOpenIncident($event, $zone);

            if ($incident) {
                $this->step('decision', 'Vigilia', 'alert', "Lo sumó al incidente #{$incident->id}, abierto hace minutos en la misma zona.");
                $this->attach($incident, $event, $verdict);
                IncidentChanged::dispatch($incident, 'detection_added');

                return $incident;
            }

            $tracked = $this->findTrackedIncident($event);
            if ($tracked) {
                [$incident, $from, $meters, $seconds] = $tracked;
                $distance = $meters !== null ? "a {$meters} m" : 'en el mismo sector';
                $this->step('decision', 'Vigilia', 'alert', "Probablemente es la misma persona del incidente #{$incident->id}: la vio {$from->name} hace {$seconds} s, {$distance}. Lo sumó al recorrido.");
                $this->attach($incident, $event, $verdict, tracked: [$from, $meters, $seconds]);
                IncidentChanged::dispatch($incident, 'detection_added');

                return $incident;
            }

            $incident = $this->open($event, $zone, $verdict);
            $this->step('decision', 'Vigilia', 'alert', "Abrió el incidente #{$incident->id} con severidad {$incident->severity->value} y avisó a la guardia.");
            if (app(AutonomyPolicy::class)->deterIfEnabled($incident, $event, $verdict, $zone)) {
                $this->step('decision', 'Vigilia', 'alert', "Disuasión automática: activó el altavoz de {$event->camera->name} sin esperar a la guardia.");
            }
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
        $zones = $event->camera->zones->reject(fn (Zone $zone): bool => $zone->kind === ZoneKind::Privacy);

        if ($zones->isEmpty()) {
            return null;
        }

        $matching = $zones->filter(fn (Zone $zone): bool => in_array($zone->frigate_zone, $event->zones ?? [], true)
            && ($event->label === 'motion' || empty($zone->watched_labels) || in_array($event->label, $zone->watched_labels, true)));

        return $matching->isEmpty() ? false : $matching->values()->all();
    }

    private function isThreat(VerificationResult $verdict): bool
    {
        if (! $verdict->isThreat) {
            return false;
        }

        return $verdict->confidence === null || $verdict->confidence >= config('vigilia.ai.threat_threshold');
    }

    private function findOpenIncident(DetectionEvent $event, ?Zone $zone): ?Incident
    {
        $since = $event->started_at->copy()->subSeconds(config('vigilia.dedup_seconds'));

        return Incident::query()
            ->where('camera_id', $event->camera_id)
            ->where('zone_id', $zone?->id)
            ->where('status', '!=', IncidentStatus::Resolved)
            ->whereHas('detectionEvents', fn ($query) => $query->where('started_at', '>=', $since))
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    /**
     * Follows someone across cameras: an open person incident in the same site whose latest detection was a
     * nearby camera (by map distance, or the same sector when cameras have no position) a few minutes ago.
     * There is no person re-identification: it is a proximity rule, and the interface says "probably".
     *
     * @return array{0: Incident, 1: Camera, 2: ?int, 3: int}|null incident, previous camera, meters, seconds
     */
    private function findTrackedIncident(DetectionEvent $event): ?array
    {
        if ($event->label !== 'person') {
            return null;
        }

        $since = $event->started_at->copy()->subSeconds(config('vigilia.tracking.window_seconds'));
        $candidates = Incident::query()
            ->where('site_id', $event->camera->site_id)
            ->where('source', IncidentSource::Detection)
            ->where('status', '!=', IncidentStatus::Resolved)
            ->whereHas('detectionEvents', fn ($query) => $query->where('label', 'person')->where('started_at', '>=', $since))
            ->lockForUpdate()
            ->latest('id')
            ->get();

        foreach ($candidates as $incident) {
            $last = $incident->detectionEvents()->with('camera')->where('label', 'person')->latest('started_at')->first();
            if (! $last || $last->camera_id === $event->camera_id) {
                continue;
            }

            $meters = Geo::distanceMeters($last->camera, $event->camera);
            $near = $meters !== null
                ? $meters <= config('vigilia.tracking.max_distance_m')
                : $last->camera->sector !== null && $last->camera->sector === $event->camera->sector;

            if ($near) {
                return [$incident, $last->camera, $meters, (int) max(0, $last->started_at->diffInSeconds($event->started_at))];
            }
        }

        return null;
    }

    private function open(DetectionEvent $event, ?Zone $zone, VerificationResult $verdict): Incident
    {
        $label = ucfirst(LabelTranslator::translate($event->label));
        $place = $zone ? "{$zone->name} ({$event->camera->name})" : $event->camera->name;
        if ($this->stoppedMinutes !== null) {
            $label .= " detenido {$this->stoppedMinutes} min";
        }

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

    /**
     * @param  array{0: Camera, 1: ?int, 2: int}|null  $tracked  previous camera, meters and seconds when following someone
     */
    private function attach(Incident $incident, DetectionEvent $event, VerificationResult $verdict, ?array $tracked = null): void
    {
        $event->forceFill(['status' => DetectionStatus::Threat, 'incident_id' => $incident->id])->save();

        $severity = $this->severity($event, $verdict, $incident->zone);
        // Someone moving through the site, seen by several cameras, is at least high.
        if ($tracked && $this->rank($severity) < $this->rank(Severity::High)) {
            $severity = Severity::High;
        }
        if ($this->rank($severity) > $this->rank($incident->severity)) {
            $incident->severity = $severity;
        }

        $incident->fill([
            'description' => $verdict->description,
            'snapshot_path' => $event->snapshot_path ?? $incident->snapshot_path,
            'clip_path' => $event->clip_path ?? $incident->clip_path,
        ])->save();

        if ($tracked) {
            [$from, $meters, $seconds] = $tracked;
            $cameras = $incident->detectionEvents()->with('camera:id,name')->orderBy('started_at')->get()->pluck('camera.name')->unique()->values();
            $incident->forceFill(['title' => 'Persona recorriendo: '.$cameras->implode(' → ')])->save();
        }

        $incident->actions()->create([
            'type' => $tracked ? 'tracked' : 'detection_added',
            'channel' => 'ai',
            'notes' => $tracked
                ? "Vista en {$event->camera->name}, {$tracked[2]} s después de {$tracked[0]->name}".($tracked[1] !== null ? " ({$tracked[1]} m)" : '').". {$verdict->description}"
                : $verdict->description,
            'meta' => ['detection_event_id' => $event->id],
        ]);
    }

    private function severity(DetectionEvent $event, VerificationResult $verdict, ?Zone $zone = null): Severity
    {
        // A5 — climbing the wall, cutting the fence or forcing a gate is an intrusion in progress.
        if (self::isRiskyAction($verdict->description)) {
            return Severity::Critical;
        }

        if ($event->label === 'person' && $zone?->kind?->isAccessPoint()) {
            return Severity::Critical;
        }

        if ($event->label === 'person') {
            return $verdict->objectCount >= 2 ? Severity::Critical : Severity::High;
        }

        return in_array($event->label, self::VEHICLE_LABELS, true) ? Severity::Medium : Severity::Low;
    }

    /**
     * A5 — what the AI described: someone climbing, cutting or forcing their way in.
     */
    public static function isRiskyAction(?string $description): bool
    {
        return $description !== null && (bool) preg_match('/\b(trep\w*|escal\w*|salt\w* (el|la|por) (muro|cerco|alambrado|reja|pared|tapial)|cort\w* (el|la) (alambrado|cerco|reja|candado)|forz\w*|rompi\w*|romp\w* (el|la))/iu', $description);
    }

    /**
     * A6 — minutes a vehicle stayed still in a watched zone, when it is long enough to matter.
     */
    private function stoppedVehicleMinutes(DetectionEvent $event): ?int
    {
        if (! in_array($event->label, ['car', 'truck', 'motorcycle'], true) || ! $event->ended_at || ! $event->started_at) {
            return null;
        }
        $minutes = (int) floor($event->started_at->diffInSeconds($event->ended_at, absolute: true) / 60);

        return $minutes >= (int) config('vigilia.tracking.stopped_vehicle_minutes', 5) ? $minutes : null;
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
