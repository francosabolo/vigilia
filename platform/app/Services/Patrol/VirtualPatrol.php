<?php

namespace App\Services\Patrol;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Jobs\RunPatrol;
use App\Models\Camera;
use App\Models\Incident;
use App\Models\Patrol;
use App\Models\Site;
use App\Models\User;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Cameras\ViewAnalyzer;
use App\Services\Rules\ArmingEvaluator;
use App\Services\Verification\AiBudget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * C1 — virtual patrol. Instead of a person flipping through monitors, the AI goes over every camera of a site,
 * compares what it sees now with that camera's usual view and reports only what is out of place.
 *
 * It uses the frame each edge already uploads every few minutes for the covered/moved check, so it needs nothing
 * new on site. Findings of medium or high severity open (or add to) one "Ronda virtual" incident, which then follows
 * the usual path: guard first, central if nobody answers.
 */
class VirtualPatrol
{
    /** A frame older than this is not "now": the camera is reported as without a recent image. */
    public const FRESH_MINUTES = 15;

    public const INTERVALS = [15, 30, 60, 120, 240];

    public function __construct(
        private readonly PatrolInspector $inspector,
        private readonly CameraViewMonitor $monitor,
        private readonly ViewAnalyzer $analyzer,
        private readonly ArmingEvaluator $arming,
        private readonly AiBudget $budget,
    ) {}

    public function start(Site $site, string $trigger, ?User $user = null): Patrol
    {
        $patrol = Patrol::create([
            'site_id' => $site->id,
            'trigger' => $trigger,
            'requested_by' => $user?->id,
            'started_at' => now(),
            'cameras_total' => $site->cameras()->count(),
        ]);

        RunPatrol::dispatch($patrol);

        return $patrol;
    }

    public function isRunning(Site $site): bool
    {
        return $site->patrols()->where('status', 'running')->where('started_at', '>', now()->subMinutes(10))->exists();
    }

    /**
     * Sites whose automatic patrol is due.
     *
     * @return Collection<int, Site>
     */
    public function dueSites(): Collection
    {
        return Site::query()
            ->whereNotNull('patrol_interval_minutes')
            ->where('is_simulated', false)
            ->get()
            ->filter(function (Site $site): bool {
                $last = $site->patrols()->latest('started_at')->value('started_at');

                return $last === null || now()->diffInMinutes($last, absolute: true) >= $site->patrol_interval_minutes;
            })
            ->values();
    }

    public function run(Patrol $patrol): Patrol
    {
        $site = $patrol->site;
        $cameras = $site->cameras()->with('zones.armingSchedules', 'zones.camera')->orderBy('sector')->orderBy('name')->get();
        $disk = Storage::disk(config('vigilia.media_disk'));
        $findings = [];
        $skipped = [];
        $checked = 0;

        foreach ($cameras as $camera) {
            $latest = $this->monitor->latestPath($camera);
            $fresh = $camera->is_online
                && $camera->view_checked_at !== null
                && $camera->view_checked_at->gt(now()->subMinutes(self::FRESH_MINUTES))
                && $disk->exists($latest);

            if (! $fresh) {
                $skipped[] = ['camera_id' => $camera->id, 'camera' => $camera->name, 'reason' => 'Sin imagen reciente'];

                continue;
            }

            if (config('vigilia.ai.enabled') && ! $this->budget->consume($site->id)) {
                $skipped[] = ['camera_id' => $camera->id, 'camera' => $camera->name, 'reason' => 'Tope diario de IA alcanzado'];

                continue;
            }

            $current = $disk->get($latest);
            $referencePath = $this->monitor->referencePath($camera, $this->analyzer->compare($current, null, $camera->fov)['bucket']);
            $reference = $disk->exists($referencePath) ? $disk->get($referencePath) : null;

            try {
                $result = $this->inspector->inspect($camera, $current, $reference, $this->context($site, $camera, $patrol->trigger));
            } catch (Throwable $exception) {
                report($exception);
                $skipped[] = ['camera_id' => $camera->id, 'camera' => $camera->name, 'reason' => 'La IA no respondió'];

                continue;
            }

            $checked++;

            if (! $result['has_novelty']) {
                continue;
            }

            $framePath = "sites/{$site->id}/patrols/{$patrol->id}/camera-{$camera->id}.jpg";
            $disk->put($framePath, $current);

            $findings[] = [
                'camera_id' => $camera->id,
                'camera' => $camera->name,
                'kind' => $result['kind'],
                'severity' => $result['severity'],
                'description' => $result['description'],
                'confidence' => $result['confidence'],
                'frame_path' => $framePath,
            ];
        }

        $patrol->forceFill([
            'status' => 'done',
            'cameras_checked' => $checked,
            'findings' => $findings,
            'skipped' => $skipped,
            'summary' => $this->summary($checked, $findings, $skipped),
            'finished_at' => now(),
        ])->save();

        $this->raise($patrol);

        return $patrol;
    }

    /**
     * Medium and high findings go to the guard as one incident per site; while that incident is open, later rounds
     * add to it instead of opening another one.
     */
    private function raise(Patrol $patrol): void
    {
        $relevant = collect($patrol->findings)->whereIn('severity', ['media', 'alta'])->values();
        if ($relevant->isEmpty()) {
            return;
        }

        $site = $patrol->site;
        $severity = $relevant->contains('severity', 'alta') ? Severity::High : Severity::Medium;
        $first = $relevant->first();
        $title = $relevant->count() === 1
            ? 'Ronda: '.mb_strtolower(Patrol::KIND_LABELS[$first['kind']]).' ('.$first['camera'].')'
            : "Ronda: {$relevant->count()} novedades";
        $description = $relevant->map(fn (array $finding): string => "{$finding['camera']}: {$finding['description']}")->implode(' ');

        $incident = Incident::query()
            ->where('site_id', $site->id)
            ->where('source', IncidentSource::Patrol)
            ->where('status', '!=', IncidentStatus::Resolved)
            ->latest('id')
            ->first();

        $opened = $incident === null;
        if ($opened) {
            $incident = Incident::create([
                'site_id' => $site->id,
                'camera_id' => $first['camera_id'],
                'source' => IncidentSource::Patrol,
                'sector' => Camera::whereKey($first['camera_id'])->value('sector'),
                'severity' => $severity,
                'status' => IncidentStatus::New,
                'title' => $title,
                'description' => $description,
                'snapshot_path' => $first['frame_path'],
                'detected_at' => now(),
            ]);
        } else {
            // D4 — the same gate or door still open on a later round: it has been open since the first one.
            $open = $relevant->firstWhere('kind', 'porton_abierto');
            $firstSeen = $open ? Patrol::where('incident_id', $incident->id)->orderBy('started_at')->get()
                ->first(fn (Patrol $previous) => collect($previous->findings)->contains(fn ($f) => $f['kind'] === 'porton_abierto' && $f['camera_id'] === $open['camera_id']))?->started_at : null;
            if ($open && $firstSeen) {
                $severity = Severity::High;
                $incident->title = 'Ronda: portón o puerta abierta hace '.(int) $firstSeen->diffInMinutes(now(), absolute: true)." min ({$open['camera']})";
            }
            if ($severity === Severity::High && $incident->severity !== Severity::Critical) {
                $incident->severity = Severity::High;
            }
            $incident->save();
        }

        $incident->actions()->create([
            'type' => 'patrol',
            'channel' => 'ai',
            'notes' => $description,
            'meta' => ['patrol_id' => $patrol->id],
        ]);
        $patrol->update(['incident_id' => $incident->id]);

        if ($opened) {
            IncidentOpened::dispatch($incident);
        }
        IncidentChanged::dispatch($incident, $opened ? 'opened' : 'patrol');
    }

    private function context(Site $site, Camera $camera, string $trigger = 'scheduled'): string
    {
        // M20 — closing a shop or a warehouse: nobody should be left inside.
        if ($trigger === 'closing') {
            return sprintf('Cámara "%s" de %s. Es el cierre: el lugar acaba de cerrar y no debería quedar ninguna persona adentro. Cualquier persona visible es una novedad de severidad alta (kind persona).', $camera->name, $site->name);
        }

        $local = now()->setTimezone($site->timezone ?? config('app.timezone'));
        $armed = $camera->zones->contains(fn ($zone) => $this->arming->isArmed($zone, now(), $site->timezone ?? config('app.timezone')));

        return sprintf(
            'Cámara "%s"%s de %s. Hora local: %s. %s',
            $camera->name,
            $camera->sector ? " (sector {$camera->sector})" : '',
            $site->name,
            $local->format('H:i'),
            $armed ? 'La zona está armada: no debería haber nadie.' : 'Horario de actividad normal: puede haber gente circulando.',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @param  array<int, array<string, mixed>>  $skipped
     */
    private function summary(int $checked, array $findings, array $skipped): string
    {
        $cameras = $checked === 1 ? '1 cámara' : "{$checked} cámaras";
        $main = match (count($findings)) {
            0 => "Sin novedades en {$cameras}",
            1 => "1 novedad en {$cameras}",
            default => count($findings)." novedades en {$cameras}",
        };

        return $skipped === [] ? "{$main}." : $main.'; '.(count($skipped) === 1 ? '1 sin revisar' : count($skipped).' sin revisar').'.';
    }
}
