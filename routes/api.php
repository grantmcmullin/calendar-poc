<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AvailabilityController;

Route::prefix('v1')->name('api.')->group(function () {
    Route::get('tenants/{tenant}/availability', AvailabilityController::class)->name('availability');
});
