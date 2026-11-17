<?php

namespace App\Services\Residents;

use App\Enums\UserRole;
use App\Models\SafeArrival;
use App\Models\User;
use App\Notifications\SafeArrivalNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * F9 — "Estoy llegando": the guard is told and watches the gate; the AI watches the resident's gate and the
 * access at any hour; if the resident does not confirm they got in, the guard is warned.
 */
class SafeArrivals
{
    public function start(User $resident, int $minutes): SafeArrival
    {
        SafeArrival::where('user_id', $resident->id)->where('status', 'arriving')->update(['status' => 'replaced']);

        $arrival = SafeArrival::create([
            'site_id' => $resident->site_id,
            'user_id' => $resident->id,
            'expected_by' => now()->addMinutes($minutes),
        ]);

        Notification::send($this->guards($arrival), new SafeArrivalNotice($arrival->load('resident', 'site')));

        return $arrival;
    }

    public function arrived(SafeArrival $arrival): void
    {
        $arrival->update(['status' => 'arrived', 'arrived_at' => now()]);
    }

    /**
     * Arrivals past their time plus a 10-minute margin without confirmation.
     */
    public function checkOverdue(): int
    {
        $overdue = SafeArrival::where('status', 'arriving')->whereNull('alerted_at')->where('expected_by', '<', now()->subMinutes(10))->with('resident', 'site')->get();

        foreach ($overdue as $arrival) {
            Notification::send($this->guards($arrival, central: true), new SafeArrivalNotice($arrival, overdue: true));
            $arrival->update(['status' => 'unconfirmed', 'alerted_at' => now()]);
        }

        return $overdue->count();
    }

    /**
     * @return Collection<int, User>
     */
    private function guards(SafeArrival $arrival, bool $central = false): Collection
    {
        return User::query()
            ->where(fn ($query) => $query->where('site_id', $arrival->site_id)->whereIn('role', [UserRole::Guard, UserRole::SiteAdmin]))
            ->when($central, fn ($query) => $query->orWhere(fn ($query) => $query->where('organization_id', $arrival->site->organization_id)->where('role', UserRole::Operator)))
            ->get();
    }
}
