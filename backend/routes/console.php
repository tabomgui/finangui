<?php

use App\Domain\Banking\Jobs\PruneSyncRuns;
use App\Domain\Banking\Jobs\SyncStaleConnections;
use App\Domain\Cards\Jobs\PostDueInstallments;
use App\Domain\Notifications\Jobs\SendAlerts;
use App\Domain\Recurrences\Jobs\GenerateRecurrences;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new PostDueInstallments)->dailyAt('00:10');
Schedule::job(new SyncStaleConnections)->everySixHours();
Schedule::job(new PruneSyncRuns)->dailyAt('03:10');
Schedule::job(new GenerateRecurrences)->dailyAt('00:20');
Schedule::job(new SendAlerts)->dailyAt('07:00');
