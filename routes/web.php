<?php

use App\Enums\UserRole;
use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ServiceDocumentTemplateController;
use App\Http\Controllers\Admin\ServiceRequirementController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationAttachmentController;
use App\Http\Controllers\Resident\QrTicketController;
use App\Http\Controllers\Resident\RequestStatusController;
use App\Http\Controllers\Resident\ReservationCancellationController;
use App\Http\Controllers\Resident\ReservationController;
use App\Http\Controllers\Resident\ReservationViewController;
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
    Route::prefix('resident/profile')->name('resident.profile.')
        ->middleware(['role:'.UserRole::Resident->value, 'verified'])->group(function () {
            Route::get('/', [ProfileController::class, 'edit'])->name('edit');
            Route::put('/', [ProfileController::class, 'update'])->block(10, 10)->name('update');
            Route::get('/picture', [ProfileController::class, 'picture'])->name('picture');
            Route::post('/picture', [ProfileController::class, 'updatePicture'])->block(10, 10)->name('picture.update');
            Route::delete('/picture', [ProfileController::class, 'destroyPicture'])->block(10, 10)->name('picture.destroy');
        });
    Route::prefix('resident/reservations')->name('resident.reservations.')
        ->middleware(['role:'.UserRole::Resident->value, 'verified'])->group(function () {
            Route::get('/{reservation}/attachments/{attachment}', [ReservationAttachmentController::class, 'resident'])
                ->whereNumber(['reservation', 'attachment'])->name('attachments.download');
            Route::get('/', [ReservationViewController::class, 'index'])->name('index');
            Route::get('/create', [ReservationController::class, 'create'])->block(10, 10)->name('create');
            Route::post('/service', [ReservationController::class, 'selectService'])->block(10, 10)->name('service');
            Route::get('/requirements', [ReservationController::class, 'requirements'])->block(10, 10)->name('requirements');
            Route::post('/requirements', [ReservationController::class, 'reviewRequirements'])->block(10, 10)->name('requirements.review');
            Route::get('/schedule', [ReservationController::class, 'schedule'])->block(10, 10)->name('schedule');
            Route::post('/schedule', [ReservationController::class, 'selectSchedule'])->block(10, 10)->name('schedule.select');
            Route::get('/confirm', [ReservationController::class, 'confirm'])->block(10, 10)->name('confirm');
            Route::post('/', [ReservationController::class, 'store'])->block(10, 10)->name('store');
            Route::get('/{reservation}', [ReservationViewController::class, 'show'])->whereNumber('reservation')->name('show');
            Route::post('/{reservation}/cancel', ReservationCancellationController::class)->whereNumber('reservation')->block(10, 10)->name('cancel');
        });
    Route::prefix('resident/qr-tickets')->name('resident.qr-tickets.')
        ->middleware(['role:'.UserRole::Resident->value, 'verified'])->group(function () {
            Route::get('/', [QrTicketController::class, 'index'])->name('index');
            Route::get('/{qrTicket}', [QrTicketController::class, 'show'])->whereNumber('qrTicket')->name('show');
            Route::get('/{qrTicket}/image', [QrTicketController::class, 'image'])->whereNumber('qrTicket')->name('image');
        });
    Route::prefix('resident/request-status')->name('resident.request-status.')
        ->middleware(['role:'.UserRole::Resident->value, 'verified'])->group(function () {
            Route::get('/', [RequestStatusController::class, 'index'])->name('index');
            Route::get('/{reservation}', [RequestStatusController::class, 'show'])->whereNumber('reservation')->name('show');
        });
    Route::prefix('admin')->name('admin.')->middleware(['role:'.UserRole::Admin->value, 'verified'])->group(function () {
        Route::get('/reservations/{reservation}/attachments/{attachment}', [ReservationAttachmentController::class, 'admin'])
            ->whereNumber(['reservation', 'attachment'])->name('reservations.attachments.download');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->block(10, 10)->name('profile.update');
        Route::get('/profile/picture', [ProfileController::class, 'picture'])->name('profile.picture');
        Route::post('/profile/picture', [ProfileController::class, 'updatePicture'])->block(10, 10)->name('profile.picture.update');
        Route::delete('/profile/picture', [ProfileController::class, 'destroyPicture'])->block(10, 10)->name('profile.picture.destroy');
        Route::get('/users/{user}/picture', [ProfileController::class, 'residentPicture'])->whereNumber('user')->name('users.picture');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->whereNumber('user')->name('users.show');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus'])->whereNumber('user')->name('users.status');
        Route::get('/reservations', [AdminReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/{reservation}', [AdminReservationController::class, 'show'])->whereNumber('reservation')->name('reservations.show');
        Route::patch('/reservations/{reservation}/status', [AdminReservationController::class, 'updateStatus'])->whereNumber('reservation')->name('reservations.status');
        Route::patch('/schedules/{schedule}/status', [ScheduleController::class, 'updateStatus'])->name('schedules.status');
        Route::resource('schedules', ScheduleController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
        Route::patch('/services/{service}/status', [ServiceController::class, 'updateStatus'])->name('services.status');
        Route::get('/services/{service}/template', [ServiceDocumentTemplateController::class, 'download'])->whereNumber('service')->name('services.template.download');
        Route::delete('/services/{service}/template', [ServiceDocumentTemplateController::class, 'destroy'])->whereNumber('service')->name('services.template.destroy');
        Route::resource('services', ServiceController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::resource('services.requirements', ServiceRequirementController::class)
            ->parameters(['requirements' => 'serviceRequirement'])->scoped()
            ->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    });
});
