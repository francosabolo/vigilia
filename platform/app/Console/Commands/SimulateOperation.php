<?php

namespace App\Console\Commands;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Events\DetectionActivity;
use App\Events\DetectionEventReceived;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentWorkflow;
use App\Services\Verification\DetectionVerifier;
use App\Simulation\OperationSimulator;
use App\Simulation\SimulatedVerifier;
use App\Simulation\SimulatedWorld;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Simulated operation at volume, to work on the software while there is no hardware.
 * The pipeline is the real one; only the cameras, the AI and the people are simulated.
 */
class SimulateOperation extends Command
{
    protected $signature = 'seguria:simulate-operation
        {--sites=8 : Simulated gated communities to create}
        {--cameras=20-40 : Cameras per site (range)}
        {--days=7 : Days of history to backfill}
        {--live : Instead of history, generate events in real time (Ctrl+C to stop)}
        {--speed=30 : In live mode, simulated minutes per real minute}
        {--recall=0.96 : Share of real intrusions the simulated AI catches}
        {--false-pass=0.05 : Share of false positives the simulated AI lets through}
        {--reset : Delete all simulated sites before starting}';

    protected $description = 'Simulate the operation of many gated communities through the real pipeline';

    /** Estimated cost per AI call in USD (see README): Haiku first pass, Sonnet for ambiguous verdicts. */
    private const COST_PER_CALL = 0.00025;

    private const COST_PER_REVIEW = 0.005;

    public function handle(SimulatedWorld $world): int
    {
        if ($this->option('reset')) {
            $this->info('Simulated sites deleted: '.$world->destroy());
        }

        $organization = Organization::where('slug', 'seguria')->first() ?? Organization::first();
        if (! $organization) {
            $this->error('Run the seeder first (php artisan migrate --seed).');

            return self::FAILURE;
        }

        $sites = Site::where('is_simulated', true)->get();
        if ($sites->isEmpty()) {
            [$min, $max] = array_map('intval', explode('-', $this->option('cameras').'-'.$this->option('cameras')));
            $this->info("Building {$this->option('sites')} simulated sites…");
            $sites = collect($world->build($organization, (int) $this->option('sites'), $min, max($min, $max)));
        }

        $verifier = new SimulatedVerifier((float) $this->option('recall'), (float) $this->option('false-pass'));
        app()->instance(DetectionVerifier::class, $verifier);
        app()->instance(SimulatedVerifier::class, $verifier);
        $simulator = app(OperationSimulator::class);

        return $this->option('live') ? $this->live($simulator, $sites) : $this->history($simulator, $sites, $verifier);
    }

    private function history(OperationSimulator $simulator, $sites, SimulatedVerifier $verifier): int
    {
        Event::fake([IncidentOpened::class, IncidentChanged::class, DetectionEventReceived::class, DetectionActivity::class]);
        $days = (int) $this->option('days');
        $this->info("Simulating {$days} days for {$sites->count()} sites ({$sites->sum(fn ($site) => $site->cameras()->count())} cameras)…");

        $started = microtime(true);
        $simulator->history($sites, $days, fn (Site $site, int $events) => $this->line("  {$site->name}: {$events} detections"));
        $report = $simulator->report($sites);

        $aiCost = $report['ai_calls'] * self::COST_PER_CALL + $report['ai_review_calls'] * self::COST_PER_REVIEW;
        $perSiteMonth = $aiCost / max(1, $sites->count()) / max(1, $days) * 30;
        $nights = max(1, $sites->count() * $days);
        $recall = $report['intrusions'] ? $report['intrusions_detected'] / $report['intrusions'] : null;

        $this->newLine();
        $this->info('Resultado de la simulación');
        $this->table(['Métrica', 'Valor'], [
            ['Movimientos detectados por los edges', number_format($report['raw_events'], 0, ',', '.')],
            ['Descartados por reglas (zona/horario/tipo)', number_format($report['ignored_by_rules'], 0, ',', '.')],
            ['Descartados por la IA', number_format($report['dismissed_by_ai'], 0, ',', '.')],
            ['Incidentes que llegaron a la guardia', $report['incidents']],
            ['Falsas alarmas por barrio por noche', number_format(($report['false_incidents']) / $nights, 2, ',', '.')],
            ['Intrusiones reales simuladas', $report['intrusions']],
            ['Intrusiones detectadas', $report['intrusions_detected'].($recall !== null ? ' ('.round($recall * 100).'%)' : '')],
            ['Perdidas por reglas / por la IA', "{$report['missed']['rules']} / {$report['missed']['ai']}".($report['missed']['reasons'] ? ' ('.collect($report['missed']['reasons'])->map(fn ($count, $reason) => match ($reason) {
                'zone_disarmed' => "{$count} de día con la zona desarmada",
                'outside_watched_zone' => "{$count} fuera de zona",
                default => "{$count} {$reason}",
            })->implode(', ').')' : '')],
            ['Escalados a la central', $report['escalated']],
            ['Respuesta promedio de la guardia', $report['avg_ack_seconds'] !== null ? "{$report['avg_ack_seconds']} s" : '—'],
            ['Llamadas a la IA (y revisiones)', "{$report['ai_calls']} ({$report['ai_review_calls']})"],
            ['Costo IA estimado del período', 'US$ '.number_format($aiCost, 2, ',', '.')],
            ['Costo IA estimado por barrio por mes', 'US$ '.number_format($perSiteMonth, 2, ',', '.')],
        ]);
        $this->line(sprintf('Tiempo de simulación: %.1f s. Los costos son estimados con precios de lista y el tamaño de una imagen 720p.', microtime(true) - $started));

        Storage::put('simulation/report-'.now()->format('Ymd-His').'.json', json_encode($report + ['ai_cost_usd' => $aiCost, 'ai_cost_per_site_month_usd' => $perSiteMonth], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function live(OperationSimulator $simulator, $sites): int
    {
        $workflow = app(IncidentWorkflow::class);
        $speed = (float) $this->option('speed');
        $tick = 5;
        /** @var array<int, int> $pendingAcks incident id → unix time the simulated guard answers */
        $pendingAcks = [];

        $this->info("Live simulation: {$sites->count()} sites, ×{$speed} speed. Open the console to watch. Ctrl+C to stop.");

        while (true) {
            foreach ($simulator->liveTick($sites, $tick, $speed) as $result) {
                $incident = $result['incident'];
                if ($incident && $incident->wasRecentlyCreated) {
                    $this->line(sprintf('[%s] %s: %s (%s)', now()->format('H:i:s'), $result['site'], $incident->title, $result['truth']->value));
                    if (mt_rand(1, 100) > 15) {
                        $pendingAcks[$incident->id] = time() + random_int(20, 150);
                    }
                }
            }

            foreach ($pendingAcks as $incidentId => $at) {
                if ($at > time()) {
                    continue;
                }
                $incident = Incident::find($incidentId);
                if ($incident && $incident->status !== IncidentStatus::Resolved) {
                    $guard = User::where('site_id', $incident->site_id)->where('role', UserRole::Guard)->first();
                    $workflow->acknowledge($incident, $guard, 'whatsapp');
                }
                unset($pendingAcks[$incidentId]);
            }

            sleep($tick);
        }
    }
}
