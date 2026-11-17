<?php

namespace App\Console\Commands;

use App\Services\Evaluation\EvalRuns;
use Illuminate\Console\Command;

class CompareEvalRuns extends Command
{
    protected $signature = 'vigilia:eval-compare {--set= : Only runs on this set path}';

    protected $description = 'Compare saved AI evaluation runs (API, batch and console)';

    public function handle(EvalRuns $runs): int
    {
        $rows = $runs->all()
            ->when($this->option('set'), fn ($all, $set) => $all->where('set', $set))
            ->map(fn ($run) => [
                substr($run['created_at'], 0, 16),
                $run['label'],
                $run['source'] === 'console' ? "consola ({$run['model']})" : $run['model'].($run['review_model'] ? ' + revisión' : ''),
                $run['set'],
                $run['metrics']['images'],
                $run['metrics']['recall'] !== null ? sprintf('%.1f%%', 100 * $run['metrics']['recall']) : '—',
                $run['metrics']['false_alarm_rate'] !== null ? sprintf('%.1f%%', 100 * $run['metrics']['false_alarm_rate']) : '—',
                sprintf('US$ %.3f', $run['cost_usd'] ?? 0),
                $run['prompt'] ?? '—',
            ]);

        if ($rows->isEmpty()) {
            $this->line('No saved runs yet. Run vigilia:eval or vigilia:eval-blind + vigilia:eval-import.');

            return self::SUCCESS;
        }

        $this->table(['Fecha', 'Corrida', 'Juez', 'Set', 'Imágenes', 'Recall', 'Falsas alarmas', 'Costo', 'Prompt'], $rows->all());

        return self::SUCCESS;
    }
}
