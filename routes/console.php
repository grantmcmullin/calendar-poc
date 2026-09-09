<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Domain\Bookings\Jobs\ReconcileBookingsJob;
use App\Domain\Notifications\Jobs\SendDueRemindersJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SendDueRemindersJob())->everyMinute();
Schedule::job(new ReconcileBookingsJob())->everyTwoMinutes();
