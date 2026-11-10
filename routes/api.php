<?php

use App\Http\Controllers\Api\IotProbeController;
use App\Http\Controllers\Api\IotScannerController;
use App\Http\Middleware\AuthenticateIotDevice;
use Illuminate\Support\Facades\Route;

Route::post('/iot/v1/probe', IotProbeController::class)
    ->middleware(['throttle:iot-ip', AuthenticateIotDevice::class, 'throttle:iot-device'])
    ->name('iot.v1.probe');

Route::middleware(['throttle:iot-ip', AuthenticateIotDevice::class, 'throttle:iot-device'])->group(function () {
    Route::post('/iot/v1/pair', [IotScannerController::class, 'pair'])->name('iot.v1.pair');
    Route::post('/iot/v1/scans', [IotScannerController::class, 'scan'])->name('iot.v1.scans');
});

// Preview/control have separate budgets: uploads cannot exhaust the scan allowance.
foreach (['status', 'preview'] as $action) {
    Route::post('/iot/v1/'.$action, [IotScannerController::class, $action])
        ->middleware(['throttle:iot-'.$action.'-ip', AuthenticateIotDevice::class, 'throttle:iot-'.$action.'-device'])
        ->name('iot.v1.'.$action);
}
