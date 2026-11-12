<?php

use App\Services\CanonicalDocumentPreview;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('smartbarangay:cleanup-unverified-users --dry-run')
    ->dailyAt('02:00')->timezone('Asia/Manila')->withoutOverlapping();

Artisan::command('smartbarangay:cleanup-document-previews', function () {
    $this->info(app(CanonicalDocumentPreview::class)->cleanup().' expired preview files removed.');
})->purpose('Remove private temporary previews older than one day');

Schedule::command('smartbarangay:cleanup-document-previews')
    ->dailyAt('02:20')->timezone('Asia/Manila')->withoutOverlapping();

Schedule::command('auth:clear-resets')
    ->dailyAt('02:10')->timezone('Asia/Manila')->withoutOverlapping();

Schedule::command('smartbarangay:prune-iot-nonces')->hourly()->withoutOverlapping()
    ->when(fn () => (bool) config('iot.enabled'));

Schedule::command('smartbarangay:prune-iot-scanner-state')->everyMinute()->withoutOverlapping()
    ->when(fn () => (bool) config('iot.enabled'));
