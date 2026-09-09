<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Domain\Calendar\Exceptions\SlotConflictException;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Exceptions\ProviderNotConfiguredException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['demo/webhook-sink', 'manage/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (SlotConflictException $e) => response()->json(['message' => $e->getMessage() ?: 'This slot is no longer available.'], 409));
        $exceptions->render(fn (ProviderApiFailedException $e) => response()->json(['message' => 'The calendar provider request failed.'], 502));
        $exceptions->render(fn (ProviderNotConfiguredException $e) => response()->json(['message' => $e->getMessage() ?: 'Calendar not configured.'], 422));
    })->create();
