<?php

namespace App\Services\Verification;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AiBudgetReached;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Daily cap of AI verifications per site, so a camera stuck on rain or a spider web all night
 * cannot run up the bill. Past the cap, detections still alert (fail-open), just without the AI filter.
 */
class AiBudget
{
    public function cap(): int
    {
        return (int) config('seguria.ai.daily_cap_per_site');
    }

    /**
     * Counts one verification and says whether it fits in today's budget.
     */
    public function consume(int $siteId): bool
    {
        if ($this->cap() <= 0) {
            return true;
        }

        $key = $this->key($siteId);
        Cache::add($key, 0, now()->endOfDay()->addHour());
        $used = Cache::increment($key);

        if ($used <= $this->cap()) {
            return true;
        }

        $this->notifyOnce($siteId);

        return false;
    }

    public function usedToday(int $siteId): int
    {
        return (int) Cache::get($this->key($siteId), 0);
    }

    private function notifyOnce(int $siteId): void
    {
        if (! Cache::add("ai-budget:notified:{$siteId}:".now()->toDateString(), true, now()->endOfDay()->addHour())) {
            return;
        }

        $site = Site::find($siteId);
        if (! $site) {
            return;
        }

        Log::warning('AI daily budget reached', ['site_id' => $siteId, 'cap' => $this->cap()]);

        $admins = User::where('organization_id', $site->organization_id)->where('role', UserRole::Admin)->get();
        Notification::send($admins, new AiBudgetReached($site, $this->cap()));
    }

    private function key(int $siteId): string
    {
        return "ai-budget:{$siteId}:".now()->toDateString();
    }
}
