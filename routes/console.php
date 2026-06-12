<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Berjalan setiap hari pada jam 10 pagi
Schedule::command('cart:send-reminders')->dailyAt('10:00');

// Update kurs mata uang 2 kali sehari (misal: 00:00 dan 12:00)
Schedule::command('currency:update-rates')
    ->timezone('Asia/Jakarta')
    ->twiceDaily(0, 12)
    ->appendOutputTo(storage_path('logs/currency-update.log'));
