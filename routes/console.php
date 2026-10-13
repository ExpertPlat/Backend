<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('privacy:cleanup')->hourly()->withoutOverlapping();

Schedule::command('case-documents:cleanup')->hourly()->withoutOverlapping();

Schedule::command('catalog:review-cases')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('invitations:expire')->everyMinute()->withoutOverlapping();

Schedule::command('commerce:expire')->everyMinute()->withoutOverlapping();

Schedule::command('finance:expire')->everyMinute()->withoutOverlapping();

Schedule::command('finance:reconcile')->everyTenMinutes()->withoutOverlapping();

// v7: confirmed sessions that ended become completed (opens the User's review of the Expert).
Artisan::command('commerce:complete-sessions', fn () => $this->info(app(\App\Services\Front\SessionCompletion::class)->run().' session(s) completed.'))
    ->purpose('Mark ended sessions and their funded agreements as completed');
Schedule::command('commerce:complete-sessions')->everyFiveMinutes()->withoutOverlapping();
