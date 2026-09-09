<?php

namespace App\Domain\Calendar\Contracts\Services;

use Carbon\CarbonImmutable;
use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Data\BusyBlockData;

interface CalendarAvailabilityServiceContract
{
    /**
     * @return array<int, BusyBlockData>
     */
    public function busyBlocks(Integration $integration, CarbonImmutable $from, CarbonImmutable $to): array;
}
