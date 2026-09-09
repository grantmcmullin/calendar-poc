<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ManageBookingController;
use App\Http\Controllers\Demo\WebhookSinkController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('demo/webhook-sink', WebhookSinkController::class)->name('demo.webhook-sink');

Route::get('manage/{token}', [ManageBookingController::class, 'show'])->name('manage.show');
Route::post('manage/{token}/cancel', [ManageBookingController::class, 'cancel'])->name('manage.cancel');
Route::post('manage/{token}/reschedule', [ManageBookingController::class, 'reschedule'])->name('manage.reschedule');
