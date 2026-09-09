<?php

namespace App\Domain\Calendar\Providers;

use Illuminate\Support\ServiceProvider;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Calendar\Services\Google\CalendarGoogleService;

class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CalendarMockService::class);
        $this->app->singleton(CalendarGoogleService::class);
        $this->app->singleton(CalendarGatewayManager::class);
    }
}
