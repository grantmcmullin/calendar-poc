<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BookingsController;
use App\Http\Controllers\Api\AvailabilityController;

Route::prefix('v1')->name('api.')->group(function () {
    Route::get('tenants/{tenant}/availability', AvailabilityController::class)->name('availability');
    Route::post('tenants/{tenant}/bookings', [BookingsController::class, 'store'])->name('bookings.store');
    Route::get('bookings/{booking:uuid}', [BookingsController::class, 'show'])->name('bookings.show');
});
