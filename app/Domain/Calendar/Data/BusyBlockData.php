<?php

namespace App\Domain\Calendar\Data;

use Carbon\CarbonImmutable;

final class BusyBlockData
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {
    }
}
