<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Setup\SetupController;
use App\Http\Controllers\ManageBookingController;
use App\Http\Controllers\Demo\WebhookSinkController;
use App\Http\Controllers\Setup\GoogleOAuthController;
use App\Http\Controllers\Setup\BookingSettingsController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('demo/webhook-sink', WebhookSinkController::class)->name('demo.webhook-sink');

Route::get('manage/{token}', [ManageBookingController::class, 'show'])->name('manage.show');
Route::post('manage/{token}/cancel', [ManageBookingController::class, 'cancel'])->name('manage.cancel');
Route::post('manage/{token}/reschedule', [ManageBookingController::class, 'reschedule'])->name('manage.reschedule');

Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
Route::put('setup/settings', [BookingSettingsController::class, 'update'])->name('setup.settings.update');
Route::get('setup/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('setup.google.redirect');
Route::get('setup/google/callback', [GoogleOAuthController::class, 'callback'])->name('setup.google.callback');
Route::post('setup/google/disconnect', [GoogleOAuthController::class, 'disconnect'])->name('setup.google.disconnect');
