<?php

namespace App\Domain\Calendar\Contracts\Services;

interface CalendarGatewayContract
{
    public function auth(): CalendarAuthServiceContract;

    public function availability(): CalendarAvailabilityServiceContract;

    public function events(): CalendarEventServiceContract;
}
