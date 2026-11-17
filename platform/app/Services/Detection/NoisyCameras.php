<?php

namespace App\Services\Detection;

use App\Enums\DetectionStatus;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\NoisyCameraNotice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * M1/M2 — a camera in a storm, with a spider web or with headlights sweeping it all night makes hundreds of
 * detections an hour, almost all dismissed. While it is that noisy, a detection right after one the AI just
 * dismissed (same camera, same kind of object) is grouped with it instead of paying the AI again. Access points
 * (doors, windows, sensitive points) are always verified, and the managers are told once a day.
 */
class NoisyCameras
{
    public const PER_HOUR = 60;

    public const DISMISSED_SHARE = 0.9;

    public const GROUP_SECONDS = 45;

    public function isNoisy(Camera $camera): bool
    {
        return Cache::remember("noisy-camera:{$camera->id}", 60, function () use ($camera): bool {
            $recent = DetectionEvent::where('camera_id', $camera->id)->where('started_at', '>=', now()->subHour())
                ->whereIn('status', [DetectionStatus::Dismissed, DetectionStatus::Threat, DetectionStatus::Observed])
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $total = (int) $recent->sum();

            return $total >= self::PER_HOUR && ((int) ($recent[DetectionStatus::Dismissed->value] ?? 0)) / $total >= self::DISMISSED_SHARE;
        });
    }

    /**
     * The dismissed detection this one can be grouped with, or null when it has to be verified.
     */
    public function groupWith(DetectionEvent $event, ?Zone $zone): ?DetectionEvent
    {
        if ($zone?->kind?->isAccessPoint() || ! $this->isNoisy($event->camera)) {
            return null;
        }

        $previous = DetectionEvent::where('camera_id', $event->camera_id)->where('id', '!=', $event->id)
            ->where('label', $event->label)->where('status', DetectionStatus::Dismissed)
            ->whereNotNull('ai_verdict->model')
            ->where('started_at', '>=', $event->started_at->copy()->subSeconds(self::GROUP_SECONDS))
            ->latest('started_at')->first();

        if ($previous) {
            $this->warnOnce($event->camera);
        }

        return $previous;
    }

    private function warnOnce(Camera $camera): void
    {
        if (! Cache::add("noisy-camera-warned:{$camera->id}:".now()->toDateString(), true, now()->endOfDay())) {
            return;
        }

        $site = $camera->site;
        $managers = User::query()
            ->where(fn ($query) => $query->where('role', UserRole::SiteAdmin)->where('site_id', $site->id))
            ->orWhere(fn ($query) => $query->where('organization_id', $site->organization_id)->whereIn('role', [UserRole::Admin, UserRole::Operator]))
            ->get();

        Notification::send($managers, new NoisyCameraNotice($camera));
    }
}
