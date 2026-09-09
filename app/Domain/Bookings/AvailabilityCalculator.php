<?php

namespace App\Domain\Bookings;

use Carbon\CarbonImmutable;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Calendar\Data\BusyBlockData;

class AvailabilityCalculator
{
    /**
     * @param  array<int, BusyBlockData>  $busyBlocks
     * @return array<int, CarbonImmutable> UTC slot start times
     */
    public function slots(BookingSettings $settings, string $timezone, CarbonImmutable $now, string $fromDate, string $toDate, array $busyBlocks): array
    {
        $slots = [];
        $length = $settings->meeting_length_minutes;
        $day = CarbonImmutable::parse($fromDate, $timezone)->startOfDay();
        $lastDay = CarbonImmutable::parse($toDate, $timezone)->startOfDay();

        while ($day->lessThanOrEqualTo($lastDay)) {
            if (in_array(strtolower($day->englishDayOfWeek), $settings->available_days, true)) {
                $cursor = $day->setTimeFromTimeString($settings->office_starts_at);
                $close = $day->setTimeFromTimeString($settings->office_ends_at);

                while ($cursor->addMinutes($length)->lessThanOrEqualTo($close)) {
                    $startUtc = $cursor->utc();
                    $endUtc = $startUtc->addMinutes($length);

                    if ($startUtc->greaterThan($now) && ! $this->overlapsAny($startUtc, $endUtc, $busyBlocks)) {
                        $slots[] = $startUtc;
                    }

                    $cursor = $cursor->addMinutes($length);
                }
            }

            $day = $day->addDay();
        }

        return $slots;
    }

    /** @param array<int, BusyBlockData> $busyBlocks */
    public function isBookable(BookingSettings $settings, string $timezone, CarbonImmutable $now, CarbonImmutable $startUtc, array $busyBlocks): bool
    {
        $date = $startUtc->setTimezone($timezone)->toDateString();

        foreach ($this->slots($settings, $timezone, $now, $date, $date, $busyBlocks) as $slot) {
            if ($slot->equalTo($startUtc)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, BusyBlockData> $busyBlocks */
    protected function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $busyBlocks): bool
    {
        foreach ($busyBlocks as $block) {
            if ($start->lessThan($block->end) && $block->start->lessThan($end)) {
                return true;
            }
        }

        return false;
    }
}
