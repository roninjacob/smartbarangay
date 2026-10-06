<?php

use App\Enums\UserRole;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Admin\ServiceController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1')->name('register.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])
        ->middleware('throttle:password-email')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:6,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/change', [EmailVerificationController::class, 'editEmail'])->name('verification.email.edit');
    Route::put('/email/change', [EmailVerificationController::class, 'updateEmail'])
        ->middleware('throttle:verification-email')->name('verification.email.update');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification-email')->name('verification.send');
    Route::get('/resident/home', [DashboardController::class, 'resident'])
        ->middleware(['role:'.UserRole::Resident->value, 'verified'])->name('resident.home');
    Route::get('/admin/home', [DashboardController::class, 'admin'])
        ->middleware(['role:'.UserRole::Admin->value, 'verified'])->name('admin.home');
    Route::prefix('admin')->name('admin.')->middleware(['role:'.UserRole::Admin->value, 'verified'])->group(function () {
        Route::patch('/services/{service}/status', [ServiceController::class, 'updateStatus'])->name('services.status');
        Route::resource('services', ServiceController::class)->only(['index', 'create', 'store', 'edit', 'update']);
    });
});
