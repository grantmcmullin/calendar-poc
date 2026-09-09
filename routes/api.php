<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AvailabilityController;

Route::prefix('v1')->name('api.')->group(function () {
    Route::get('tenants/{tenant}/availability', AvailabilityController::class)->name('availability');
    Route::get('bookings/{booking:uuid}', fn () => abort(501))->name('bookings.show');
});
