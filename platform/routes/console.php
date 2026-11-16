<?php

use Illuminate\Support\Facades\Schedule;

// A security system that goes blind must say so: edge boxes and cameras are checked every minute.
Schedule::command('seguria:watch-equipment')->everyMinute()->withoutOverlapping();

// Images of people are personal data (Ley 25.326): delete them once they are no longer useful.
Schedule::command('seguria:prune-media')->dailyAt('04:30')->withoutOverlapping();
