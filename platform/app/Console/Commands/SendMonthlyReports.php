<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\SiteReport;
use App\Models\User;
use App\Notifications\MonthlyReportReady;
use App\Services\Reports\MonthlyReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * G1 — on the 1st, each client's administration and each site administrator gets last month's report.
 */
class SendMonthlyReports extends Command
{
    protected $signature = 'seguria:monthly-reports {--month= : YYYY-MM, last month by default}';

    protected $description = "Write and e-mail last month's service report of every site";

    public function handle(MonthlyReport $reports): int
    {
        $month = $this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m');

        foreach (Site::where('is_simulated', false)->get() as $site) {
            if (SiteReport::where('site_id', $site->id)->where('month', $month)->whereNotNull('sent_at')->exists()) {
                continue;
            }

            $report = $reports->build($site, $month);
            $recipients = User::query()
                ->where(fn ($query) => $query
                    ->where(fn ($q) => $q->where('role', UserRole::SiteAdmin)->where('site_id', $site->id))
                    ->when($site->client_id, fn ($q) => $q->orWhere(fn ($q) => $q->where('role', UserRole::ClientAdmin)->where('client_id', $site->client_id))))
                ->get();

            Notification::send($recipients, new MonthlyReportReady($site, $month, $report['month_label'], $report['narrative']));
            SiteReport::where('site_id', $site->id)->where('month', $month)->update(['sent_at' => now()]);
            $this->line("{$site->name}: enviado a {$recipients->count()}");
        }

        return self::SUCCESS;
    }
}
