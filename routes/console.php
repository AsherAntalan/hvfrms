<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('alerts:generate')->hourly();

// Soft-remove expired-batch archive entries past their 30-day retention window
// (Asia/Manila). Reporting lifecycle only — no inventory/audit data is deleted.
Schedule::command('archives:prune-expired')->dailyAt('01:00');
