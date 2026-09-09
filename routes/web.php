<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Demo\WebhookSinkController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('demo/webhook-sink', WebhookSinkController::class)->name('demo.webhook-sink');
