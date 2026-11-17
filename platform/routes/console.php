<?php

use Illuminate\Support\Facades\Schedule;

// A security system that goes blind must say so: edge boxes and cameras are checked every minute.
Schedule::command('vigilia:watch-equipment')->everyMinute()->withoutOverlapping();

// Images of people are personal data (Ley 25.326): delete them once they are no longer useful.
Schedule::command('vigilia:prune-media')->dailyAt('04:30')->withoutOverlapping();

// Virtual patrol: the AI goes over every camera of the sites that have it turned on.
Schedule::command('vigilia:patrol')->everyMinute()->withoutOverlapping();

// G1: last month's service report for every client, on the 1st in the morning.
Schedule::command('vigilia:monthly-reports')->monthlyOn(1, '08:00')->withoutOverlapping();

// C2/C3: guard posts and round checkpoints, where the company turned supervision on.
Schedule::command('vigilia:watch-guards')->everyFiveMinutes()->withoutOverlapping();

// F6: residents get one sentence about their week, Monday morning.
Schedule::command('vigilia:resident-weekly')->weeklyOn(1, '09:00')->withoutOverlapping();

// F9: residents who said they were arriving and never confirmed they got in.
Schedule::command('vigilia:check-arrivals')->everyMinute()->withoutOverlapping();

// I7: a random sample of what the AI dismissed, for a person to review, Monday morning.
Schedule::command('vigilia:sample-dismissed')->weeklyOn(1, '08:00')->withoutOverlapping();
