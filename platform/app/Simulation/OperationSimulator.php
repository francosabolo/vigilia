<?php

namespace App\Simulation;

use App\Enums\DetectionStatus;
use App\Enums\IncidentResolution;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\DetectionProcessor;
use App\Services\Incidents\IncidentWorkflow;
use App\Services\Residents\ResidentAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generates realistic operation for simulated sites and runs it through the real pipeline.
 * Each event carries a hidden ground truth so the report can say what the system caught and what it missed.
 */
class OperationSimulator
{
    /**
     * Expected events per camera per hour, by truth. Night = 20:00-07:00.
     *
     * @var array<string, array{night: float, day: float}>
     */
    private const RATES = [
        'animal' => ['night' => 0.45, 'day' => 0.15],
        'weather' => ['night' => 0.25, 'day' => 0.05],
        'guard_patrol' => ['night' => 0.15, 'day' => 0.05],
        'resident' => ['night' => 0.05, 'day' => 0.6],
        'vehicle' => ['night' => 0.1, 'day' => 0.4],
        'loitering' => ['night' => 0.012, 'day' => 0.006],
    ];

    /** Real intrusion attempts per site per week (mostly at night). */
    private const INTRUSIONS_PER_WEEK = 1.5;

    /** @var array<int, array{truth: Truth, site_id: int, started_at: CarbonImmutable}> intrusion attempts by id */
    public array $intrusions = [];

    /** @var array<string, int> external_id → intrusion id */
    private array $intrusionEvents = [];

    public function __construct(
        private readonly SimulatedVerifier $verifier,
        private readonly DetectionProcessor $processor,
        private readonly IncidentWorkflow $workflow,
        private readonly ResidentAlerts $residentAlerts,
        private readonly DemoImages $images,
    ) {}

    /**
     * Backfills $days of operation ending now. Notifications are not sent; guards are simulated.
     *
     * @param  Collection<int, Site>  $sites
     */
    public function history(Collection $sites, int $days, ?callable $progress = null): void
    {
        $end = CarbonImmutable::now()->startOfHour();
        $start = $end->subDays($days);

        foreach ($sites as $site) {
            $cameras = $site->cameras()->with('zones')->get();
            $events = $this->scenario($site, $cameras, $start, $end)->sortBy('at')->values();

            foreach ($events as $event) {
                Carbon::setTestNow($event['at']);
                $incident = $this->process($event);
                if ($incident && $incident->wasRecentlyCreated) {
                    $this->simulateResponse($incident, $event['truth']);
                }
            }

            $this->residentActivity($site, $start, $end);
            Carbon::setTestNow();
            $progress && $progress($site, $events->count());
        }

        Carbon::setTestNow();
    }

    /**
     * One live tick: generates the events that happen in the next $seconds (times $speed) and processes them now.
     *
     * @param  Collection<int, Site>  $sites
     * @return array<int, array{site: string, truth: Truth, incident: ?Incident}>
     */
    public function liveTick(Collection $sites, int $seconds, float $speed): array
    {
        $now = CarbonImmutable::now();
        $results = [];

        foreach ($sites as $site) {
            $cameras = $site->cameras()->with('zones')->get();
            $window = $now->addSeconds((int) ($seconds * $speed));
            foreach ($this->scenario($site, $cameras, $now, $window) as $event) {
                $event['at'] = $now;
                $results[] = ['site' => $site->name, 'truth' => $event['truth'], 'incident' => $this->process($event)];
            }
        }

        return $results;
    }

    /**
     * @return array{intrusions: int, intrusions_detected: int, missed: array<string, int>, raw_events: int, ignored_by_rules: int, dismissed_by_ai: int, incidents: int, false_incidents: int, escalated: int, avg_ack_seconds: ?int, ai_calls: int, ai_review_calls: int}
     */
    public function report(Collection $sites): array
    {
        $siteIds = $sites->pluck('id');
        $events = DetectionEvent::query()->whereHas('camera', fn ($query) => $query->whereIn('site_id', $siteIds));
        $incidents = Incident::query()->whereIn('site_id', $siteIds)->where('source', 'detection');

        $detected = 0;
        $missed = ['rules' => 0, 'ai' => 0, 'reasons' => []];
        foreach (collect($this->intrusionEvents)->groupBy(fn ($id) => $id) as $intrusionId => $_) {
            $externalIds = array_keys(array_filter($this->intrusionEvents, fn ($id) => $id === $intrusionId));
            $statuses = DetectionEvent::whereIn('external_id', $externalIds)->pluck('status');
            if ($statuses->contains(DetectionStatus::Threat)) {
                $detected++;
            } elseif ($statuses->contains(DetectionStatus::Dismissed)) {
                $missed['ai']++;
            } else {
                $missed['rules']++;
                $reason = DetectionEvent::whereIn('external_id', $externalIds)->get()->map(fn ($event) => $event->ai_verdict['skipped'] ?? 'unknown')->first();
                $missed['reasons'][$reason] = ($missed['reasons'][$reason] ?? 0) + 1;
            }
        }

        $ackSeconds = (clone $incidents)->whereNotNull('acknowledged_at')->get(['detected_at', 'acknowledged_at'])
            ->map(fn (Incident $incident) => $incident->detected_at->diffInSeconds($incident->acknowledged_at));

        return [
            'intrusions' => count($this->intrusions),
            'intrusions_detected' => $detected,
            'missed' => $missed,
            'raw_events' => (clone $events)->count(),
            'ignored_by_rules' => (clone $events)->where('status', DetectionStatus::Ignored)->count(),
            'dismissed_by_ai' => (clone $events)->where('status', DetectionStatus::Dismissed)->count(),
            'incidents' => (clone $incidents)->count(),
            'false_incidents' => (clone $incidents)->where('resolution', IncidentResolution::FalseAlarm)->count(),
            'escalated' => (clone $incidents)->whereNotNull('escalated_at')->count(),
            'avg_ack_seconds' => $ackSeconds->isEmpty() ? null : (int) round($ackSeconds->avg()),
            'ai_calls' => $this->verifier->calls,
            'ai_review_calls' => $this->verifier->reviewCalls,
        ];
    }

    /**
     * @param  Collection<int, Camera>  $cameras
     * @return Collection<int, array{at: CarbonImmutable, camera: Camera, truth: Truth, label: string, in_zone: bool, intrusion: ?int}>
     */
    private function scenario(Site $site, Collection $cameras, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $events = collect();
        if ($cameras->isEmpty()) {
            return $events;
        }

        $hours = max(1 / 3600, $from->diffInSeconds($to) / 3600);

        foreach ($cameras as $camera) {
            foreach (self::RATES as $kind => $rate) {
                $truth = Truth::from($kind);
                $count = $this->poisson(($rate['night'] * 0.46 + $rate['day'] * 0.54) * $hours);
                for ($i = 0; $i < $count; $i++) {
                    $at = $this->randomTime($from, $to, $rate['night'] / max(0.0001, $rate['night'] + $rate['day']));
                    $events->push($this->event($camera, $truth, $at));
                }
            }
        }

        // Intrusion attempts: several detections within a few minutes on neighboring cameras.
        $attempts = $this->poisson(self::INTRUSIONS_PER_WEEK * $hours / 168);
        for ($a = 0; $a < $attempts; $a++) {
            $id = count($this->intrusions) + 1;
            $at = $this->randomTime($from, $to, 0.85);
            $this->intrusions[$id] = ['truth' => Truth::Intrusion, 'site_id' => $site->id, 'started_at' => $at];
            $start = random_int(0, max(0, $cameras->count() - 2));
            foreach ($cameras->slice($start, random_int(1, 2)) as $camera) {
                for ($d = 0, $n = random_int(2, 4); $d < $n; $d++) {
                    $events->push($this->event($camera, Truth::Intrusion, $at->addSeconds(random_int(0, 180)), $id));
                }
            }
        }

        return $events;
    }

    /**
     * @return array{at: CarbonImmutable, camera: Camera, truth: Truth, label: string, in_zone: bool, intrusion: ?int}
     */
    private function event(Camera $camera, Truth $truth, CarbonImmutable $at, ?int $intrusion = null): array
    {
        $label = match ($truth) {
            Truth::Animal => mt_rand(1, 100) <= 25 ? 'person' : 'dog',
            Truth::Vehicle => 'car',
            default => 'person',
        };
        $inZone = match ($truth) {
            Truth::Intrusion => true,
            Truth::Vehicle, Truth::Loitering => mt_rand(1, 100) <= 30,
            default => mt_rand(1, 100) <= 70,
        };

        return ['at' => $at, 'camera' => $camera, 'truth' => $truth, 'label' => $label, 'in_zone' => $inZone, 'intrusion' => $intrusion];
    }

    /**
     * @param  array{at: CarbonImmutable, camera: Camera, truth: Truth, label: string, in_zone: bool, intrusion: ?int}  $event
     */
    private function process(array $event): ?Incident
    {
        $externalId = 'sim-'.Str::uuid();
        $this->verifier->remember($externalId, $event['truth']);
        if ($event['intrusion']) {
            $this->intrusionEvents[$externalId] = $event['intrusion'];
        }

        $detection = DetectionEvent::create([
            'edge_device_id' => $event['camera']->edge_device_id,
            'camera_id' => $event['camera']->id,
            'external_id' => $externalId,
            'label' => $event['label'],
            'score' => round(0.6 + mt_rand(0, 35) / 100, 2),
            'zones' => $event['in_zone'] ? ['alambrado'] : [],
            'started_at' => $event['at'],
            'ended_at' => $event['at']->addSeconds(random_int(4, 40)),
            'snapshot_path' => $this->images->pathFor($this->imageGroup($event)),
        ]);
        $detection->setRelation('camera', $event['camera']);

        return $this->processor->process($detection);
    }

    /**
     * Which picture dresses a simulated detection, so what the AI "saw" matches what really happened.
     *
     * @param  array{camera: Camera, truth: Truth}  $event
     */
    private function imageGroup(array $event): string
    {
        $isDoor = $event['camera']->zones->contains(fn ($zone) => $zone->kind->isAccessPoint());

        return match ($event['truth']) {
            Truth::Intrusion => $isDoor ? 'intrusion_door' : 'intrusion',
            Truth::Loitering => 'loitering',
            Truth::Animal => 'animal',
            Truth::Weather => 'weather',
            Truth::GuardPatrol => 'guard_patrol',
            Truth::Vehicle => 'vehicle',
            Truth::Resident => 'empty',
        };
    }

    /**
     * Guards: most acknowledge in 30 s to 4 min; ~10% do not answer and it escalates.
     */
    private function simulateResponse(Incident $incident, Truth $truth): void
    {
        $detectedAt = CarbonImmutable::instance($incident->detected_at);
        $site = $incident->site;
        $guard = User::where('site_id', $site->id)->where('role', UserRole::Guard)->first();
        $operator = User::where('organization_id', $site->organization_id)->whereIn('role', [UserRole::Operator, UserRole::Admin])->first();

        $this->workflow->markNotified($incident, ['guards']);
        $answers = mt_rand(1, 100) > 10;

        if ($answers) {
            Carbon::setTestNow($detectedAt->addSeconds((int) min(900, max(20, exp(4.4 + $this->gaussian() * 0.6)))));
            $this->workflow->acknowledge($incident, $guard, mt_rand(1, 100) <= 70 ? 'whatsapp' : 'web');
        } else {
            Carbon::setTestNow($detectedAt->addSeconds($site->ack_timeout_seconds));
            $this->workflow->escalate($incident);
            Carbon::setTestNow($detectedAt->addSeconds($site->ack_timeout_seconds + random_int(30, 150)));
            $this->workflow->acknowledge($incident, $operator, 'web');
        }

        Carbon::setTestNow(now()->addMinutes(random_int(3, 12)));
        $resolution = $truth->isThreat() ? IncidentResolution::Confirmed : IncidentResolution::FalseAlarm;
        $this->workflow->resolve($incident, $resolution, $answers ? $guard : $operator, 'web', $truth->isThreat() ? 'Se dio aviso al 911.' : null);
    }

    private function residentActivity(Site $site, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $residents = User::where('site_id', $site->id)->where('role', UserRole::Resident)->get();
        $sectors = $site->cameras()->whereNotNull('sector')->distinct()->pluck('sector')->all();
        $reports = $this->poisson(2 * $from->diffInDays($to) / 7);

        for ($i = 0; $i < $reports && $residents->isNotEmpty() && $sectors; $i++) {
            Carbon::setTestNow($this->randomTime($from, $to, 0.7));
            $incident = $this->residentAlerts->report($residents->random(), $sectors[array_rand($sectors)], 'Vi a alguien raro del lado de afuera.');
            $incident->snapshotRequests()->update(['status' => 'expired']);
            $this->simulateResponse($incident, Truth::Loitering);
        }
    }

    private function randomTime(CarbonImmutable $from, CarbonImmutable $to, float $nightShare): CarbonImmutable
    {
        for ($tries = 0; $tries < 20; $tries++) {
            $at = $from->addSeconds(random_int(0, max(1, $from->diffInSeconds($to))));
            $local = $at->setTimezone('America/Argentina/Buenos_Aires');
            $isNight = $local->hour >= 20 || $local->hour < 7;
            if ((mt_rand() / mt_getrandmax()) < ($isNight ? $nightShare : 1 - $nightShare) * 1.6) {
                return $at;
            }
        }

        return $from->addSeconds(random_int(0, max(1, $from->diffInSeconds($to))));
    }

    private function poisson(float $lambda): int
    {
        if ($lambda > 30) {
            return max(0, (int) round($lambda + sqrt($lambda) * $this->gaussian()));
        }
        $limit = exp(-$lambda);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $limit);

        return $k - 1;
    }

    private function gaussian(): float
    {
        return sqrt(-2 * log(max(1e-9, mt_rand() / mt_getrandmax()))) * cos(2 * M_PI * mt_rand() / mt_getrandmax());
    }
}
