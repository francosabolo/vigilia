<?php

namespace App\Services\Residents;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who is actually using the residents app: active sessions (by device) and devices with push alerts on.
 */
class ResidentDevices
{
    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array{active_sessions: int, devices: array<int, string>, last_active_at: ?string, push_devices: int}>
     */
    public function forUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $since = now()->subMinutes((int) config('session.lifetime'))->timestamp;

        $sessions = DB::table('sessions')
            ->whereIn('user_id', $userIds)
            ->where('last_activity', '>=', $since)
            ->get(['user_id', 'user_agent', 'last_activity'])
            ->groupBy('user_id');

        $push = DB::table('push_subscriptions')
            ->where('subscribable_type', (new User)->getMorphClass())
            ->whereIn('subscribable_id', $userIds)
            ->selectRaw('subscribable_id, count(*) as total')
            ->groupBy('subscribable_id')
            ->pluck('total', 'subscribable_id');

        $result = [];

        foreach ($userIds as $id) {
            $userSessions = $sessions->get($id, collect());
            $lastActivity = $userSessions->max('last_activity');

            $result[$id] = [
                'active_sessions' => $userSessions->count(),
                'devices' => $userSessions->map(fn ($session): string => $this->deviceName((string) $session->user_agent))->unique()->values()->all(),
                'last_active_at' => $lastActivity ? now()->setTimestamp((int) $lastActivity)->toIso8601String() : null,
                'push_devices' => (int) ($push[$id] ?? 0),
            ];
        }

        return $result;
    }

    private function deviceName(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Otro dispositivo',
        };
    }
}
