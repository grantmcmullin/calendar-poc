<?php

namespace App\Domain\Calendar\Services\Google;

use App\Domain\Calendar\Contracts\Services\CalendarGatewayContract;
use App\Domain\Calendar\Contracts\Services\CalendarAuthServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarEventServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarAvailabilityServiceContract;

class CalendarGoogleService implements CalendarGatewayContract
{
    public function auth(): CalendarAuthServiceContract
    {
        return app(CalendarAuthGoogleService::class);
    }

    public function availability(): CalendarAvailabilityServiceContract
    {
        return app(CalendarAvailabilityGoogleService::class); // implemented in Task 6
    }

    public function events(): CalendarEventServiceContract
    {
        return app(CalendarEventGoogleService::class); // implemented in Task 6
    }
}
