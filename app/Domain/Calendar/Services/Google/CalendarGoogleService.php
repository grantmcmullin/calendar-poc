<?php

namespace App\Domain\Calendar\Services\Google;

use App\Domain\Calendar\Exceptions\ProviderNotConfiguredException;
use App\Domain\Calendar\Contracts\Services\CalendarGatewayContract;
use App\Domain\Calendar\Contracts\Services\CalendarAuthServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarEventServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarAvailabilityServiceContract;

class CalendarGoogleService implements CalendarGatewayContract
{
    public function auth(): CalendarAuthServiceContract
    {
        throw new ProviderNotConfiguredException('pending Task 5/6');
    }

    public function availability(): CalendarAvailabilityServiceContract
    {
        throw new ProviderNotConfiguredException('pending Task 5/6');
    }

    public function events(): CalendarEventServiceContract
    {
        throw new ProviderNotConfiguredException('pending Task 5/6');
    }
}
