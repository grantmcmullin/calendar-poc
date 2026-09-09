<?php

namespace App\Domain\Bookings\Actions;

use Carbon\CarbonImmutable;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Data\InviteeData;
use App\Domain\Bookings\Enums\CancellationSource;

class RescheduleBookingAction
{
    public function __construct(protected CancelBookingAction $cancel, protected CreateBookingAction $create)
    {
    }

    public function execute(Booking $booking, CarbonImmutable $newStartTime): Booking
    {
        // Order matters: create-first would see the old booking as an own-booking busy block.
        $this->cancel->execute($booking, CancellationSource::Lead);

        return $this->create->execute(
            $booking->tenant,
            $newStartTime,
            new InviteeData($booking->lead_first_name, $booking->lead_last_name, $booking->lead_email, $booking->lead_phone, $booking->lead_timezone),
            $booking->tracking ?? [],
            rescheduledFrom: $booking,
        );
    }
}
