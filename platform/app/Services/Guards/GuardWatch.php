<?php

namespace App\Services\Guards;

use Anthropic\Client;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\GuardSighting;
use App\Models\Site;
use App\Models\User;
use App\Notifications\GuardSupervisionAlert;
use App\Services\Cameras\CameraViewMonitor;
use App\Services\Verification\AiBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * C2 — is someone at the guard post? C3 — were the round checkpoints visited? Both alert the supervisors, never
 * the guard, and only where the company turned them on and confirmed the guards were informed.
 */
class GuardWatch
{
    public function __construct(private readonly CameraViewMonitor $monitor, private readonly AiBudget $budget) {}

    /**
     * A detection the AI recognized as a uniformed guard at a round checkpoint counts as a visit.
     */
    public function recordFromDetection(DetectionEvent $event): void
    {
        $camera = $event->camera;
        if (! $camera?->is_checkpoint || ! ($event->ai_verdict['suspected_guard'] ?? false)) {
            return;
        }

        GuardSighting::create([
            'site_id' => $camera->site_id,
            'camera_id' => $camera->id,
            'source' => 'detection',
            'description' => $event->ai_verdict['description'] ?? null,
            'seen_at' => $event->started_at ?? now(),
        ]);
    }

    public function run(): void
    {
        $sites = Site::query()->whereNotNull('guard_monitoring_consent_at')->where('is_simulated', false)
            ->where(fn ($query) => $query->whereNotNull('guard_post_max_absence_minutes')->orWhereNotNull('round_interval_minutes'))
            ->with('cameras')->get();

        foreach ($sites as $site) {
            if ($site->guard_post_max_absence_minutes) {
                $site->cameras->where('is_guard_post', true)->each(fn (Camera $camera) => $this->checkPost($site, $camera));
            }
            if ($site->round_interval_minutes) {
                $this->checkRounds($site);
            }
        }
    }

    public function checkPost(Site $site, Camera $camera): void
    {
        $last = GuardSighting::where('camera_id', $camera->id)->where('source', 'post_check')->latest('seen_at')->first();
        if ($last && $last->seen_at->gt(now()->subMinutes(5))) {
            return;
        }

        $disk = Storage::disk(config('seguria.media_disk'));
        $path = $this->monitor->latestPath($camera);
        if (! $disk->exists($path) || ! config('seguria.ai.enabled') || ! $this->budget->consume($site->id)) {
            return;
        }

        try {
            $message = app(Client::class)->messages->create(
                model: config('seguria.ai.model'),
                maxTokens: 512,
                system: 'Mirás la cámara de la garita de seguridad de un edificio o barrio. Respondé si hay alguien en el puesto.',
                messages: [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($disk->get($path))]],
                    ['type' => 'text', 'text' => "Cámara de la garita: {$camera->name}."],
                ]]],
                outputConfig: ['format' => GuardPostVerdict::class, 'effort' => 'low'],
            );
            $verdict = $message->parsedOutput();
        } catch (Throwable $exception) {
            report($exception);

            return;
        }
        if (! $verdict instanceof GuardPostVerdict) {
            return;
        }

        GuardSighting::create([
            'site_id' => $site->id, 'camera_id' => $camera->id, 'source' => 'post_check',
            'present' => $verdict->person_present, 'description' => $verdict->description, 'seen_at' => now(),
        ]);

        if (! $verdict->person_present) {
            $since = GuardSighting::where('camera_id', $camera->id)->where('source', 'post_check')->where('present', true)->latest('seen_at')->value('seen_at');
            $minutes = $since ? (int) $since->diffInMinutes(now(), absolute: true) : null;
            if ($minutes === null || $minutes >= $site->guard_post_max_absence_minutes) {
                $this->alertOnce($site, "post:{$camera->id}", "Garita vacía en {$site->name}", ($minutes ? "Nadie en {$camera->name} hace {$minutes} minutos." : "Nadie en {$camera->name}.").' '.$verdict->description);
            }
        }
    }

    public function checkRounds(Site $site): void
    {
        $limit = (int) round($site->round_interval_minutes * 1.5);

        foreach ($site->cameras->where('is_checkpoint', true) as $camera) {
            $last = GuardSighting::where('camera_id', $camera->id)->where('source', 'detection')->max('seen_at');
            $since = $last ? now()->diffInMinutes($last, absolute: true) : null;

            if ($since === null || $since >= $limit) {
                $this->alertOnce($site, "round:{$camera->id}", "Ronda sin pasar por {$camera->name}", $last
                    ? "En {$site->name} ningún guardia pasó por {$camera->name} hace ".(int) $since.' minutos (la ronda es cada '.$site->round_interval_minutes.' minutos).'
                    : "En {$site->name} todavía no se registró ningún guardia en {$camera->name}.");
            }
        }
    }

    /**
     * The supervisors: the property's administration and the central; one alert per problem per hour.
     */
    private function alertOnce(Site $site, string $key, string $title, string $body): void
    {
        if (! Cache::add("guard-watch:{$site->id}:{$key}", true, now()->addHour())) {
            return;
        }

        $supervisors = User::query()
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('organization_id', $site->organization_id)->whereIn('role', [UserRole::Admin, UserRole::Operator]))
                ->orWhere(fn ($q) => $q->where('role', UserRole::SiteAdmin)->where('site_id', $site->id)))
            ->get();

        Notification::send($supervisors, new GuardSupervisionAlert($site, $title, $body));
    }
}
