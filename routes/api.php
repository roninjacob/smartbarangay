<?php

use App\Http\Controllers\Api\IotProbeController;
use App\Http\Middleware\AuthenticateIotDevice;
use Illuminate\Support\Facades\Route;

Route::post('/iot/v1/probe', IotProbeController::class)
    ->middleware(['throttle:iot-ip', AuthenticateIotDevice::class, 'throttle:iot-device'])
    ->name('iot.v1.probe');
