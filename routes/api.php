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
