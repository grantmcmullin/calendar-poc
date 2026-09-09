<?php

namespace App\Domain\Calendar\Services\Google;

use Carbon\CarbonImmutable;
use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Contracts\Services\CalendarAvailabilityServiceContract;

class CalendarAvailabilityGoogleService implements CalendarAvailabilityServiceContract
{
    public function busyBlocks(Integration $integration, CarbonImmutable $from, CarbonImmutable $to): array
    {
        throw new ProviderApiFailedException('pending Task 6');
    }
}
