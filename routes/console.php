<?php

use App\Jobs\SyncConnectedGmailStatements;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncConnectedGmailStatements)
    ->everyFifteenMinutes()
    ->withoutOverlapping(30)
    ->onOneServer()
    ->when(fn (): bool => (bool) config('gmail_statement.enabled'));

Schedule::command('pospilot:send-business-reminders')
    ->dailyAt('18:30')
    ->timezone('Africa/Lagos')
    ->withoutOverlapping();
