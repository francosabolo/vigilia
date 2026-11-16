<?php

namespace App\Console\Commands\Concerns;

/**
 * Simulation tools write fake detections, cameras and incidents: they must never touch a real deployment.
 */
trait RefusesInProduction
{
    protected function refusesInProduction(): bool
    {
        if (! app()->isProduction()) {
            return false;
        }

        $this->error('This command creates simulated data and is disabled in production.');

        return true;
    }
}
