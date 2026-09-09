<?php

namespace App\Domain\Calendar\Data;

use Carbon\CarbonImmutable;

final class CalendarEventDraftData
{
    public function __construct(
        public readonly string $summary,
        public readonly string $description,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $timezone,
        public readonly string $attendeeEmail,
        public readonly string $attendeeName,
        public readonly string $bookingUuid,
    ) {
    }
}
