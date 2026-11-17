<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\QualitySampleReady;
use App\Services\Quality\DismissedSample;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SampleDismissed extends Command
{
    protected $signature = 'seguria:sample-dismissed';

    protected $description = 'Pick a random weekly sample of dismissed detections for a person to review (I7)';

    public function handle(DismissedSample $sample): int
    {
        $count = $sample->sample();

        if ($count > 0) {
            Notification::send(User::whereIn('role', [UserRole::Admin, UserRole::Operator])->get(), new QualitySampleReady($count));
        }
        $this->line("{$count} detecciones para revisar.");

        return self::SUCCESS;
    }
}
