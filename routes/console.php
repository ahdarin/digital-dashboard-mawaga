<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\RecomputeDelayRiskScores;
use App\Console\Commands\SendDelayRiskNotifications;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('analytics:detect-anomalies')->hourly();

Schedule::command(RecomputeDelayRiskScores::class)->hourly();
Schedule::command(SendDelayRiskNotifications::class)->dailyAt('08:00');
