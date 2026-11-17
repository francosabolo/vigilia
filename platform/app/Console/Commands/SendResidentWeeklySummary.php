<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Notifications\ResidentWeeklySummary;
use App\Services\Reports\SiteMetrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendResidentWeeklySummary extends Command
{
    protected $signature = 'seguria:resident-weekly';

    protected $description = 'Send every resident one sentence about how the week went in their property';

    public function handle(SiteMetrics $metrics): int
    {
        foreach (Site::where('is_simulated', false)->get() as $site) {
            $residents = $site->residents()->get();
            if ($residents->isEmpty()) {
                continue;
            }

            Notification::send($residents, new ResidentWeeklySummary($site->name, self::sentence($metrics->forSites([$site->id], now()->subWeek(), now()))));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $m
     */
    public static function sentence(array $m): string
    {
        $ignored = $m['filtered_by_ai'] + $m['outside_schedule'];
        $base = "La IA revisó {$m['detections']} movimientos y descartó {$ignored} sin molestar a nadie.";

        return match (true) {
            $m['incidents'] === 0 => "{$base} No hubo alertas: semana tranquila.",
            $m['confirmed'] === 0 => "{$base} La guardia revisó ".($m['incidents'] === 1 ? '1 alerta' : "{$m['incidents']} alertas").' y ninguna fue una intrusión.',
            default => "{$base} La guardia atendió {$m['incidents']} alertas y frenó ".($m['confirmed'] === 1 ? '1 intrusión.' : "{$m['confirmed']} intrusiones."),
        };
    }
}
