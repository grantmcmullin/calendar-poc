<?php

namespace App\Domain\Bookings\Actions;

use Carbon\CarbonImmutable;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Data\InviteeData;
use App\Domain\Bookings\AvailabilityCalculator;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Calendar\Exceptions\SlotConflictException;
use App\Domain\Calendar\Exceptions\ProviderNotConfiguredException;

class RescheduleBookingAction
{
    public function __construct(
        protected CancelBookingAction $cancel,
        protected CreateBookingAction $create,
        protected CalendarGatewayManager $manager,
        protected AvailabilityCalculator $calculator,
    ) {
    }

    public function execute(Booking $booking, CarbonImmutable $newStartTime): Booking
    {
        // Pre-validate the new slot BEFORE canceling: if we canceled first and the new
        // slot turned out to be unavailable, the lead would be left with nothing (see
        // task-14 review). CreateBookingAction's own lock/guard remains the backstop.
        $this->assertNewSlotIsBookable($booking, $newStartTime);

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

    protected function assertNewSlotIsBookable(Booking $booking, CarbonImmutable $newStartTime): void
    {
        $tenant = $booking->tenant;

        $integration = $tenant->integration
            ?? throw new ProviderNotConfiguredException('No calendar integration is connected for this tenant.');

        $settings = $tenant->bookingSettings
            ?? throw new ProviderNotConfiguredException('Booking settings are not configured for this tenant.');

        $newEndTime = $newStartTime->addMinutes($settings->meeting_length_minutes);

        $busy = [
            ...$this->manager->for($integration)->availability()->busyBlocks($integration, $newStartTime, $newEndTime),
            ...Booking::query()->confirmed()
                ->where('tenant_id', $tenant->id)
                ->where('id', '!=', $booking->id) // exclude the booking being rescheduled
                ->where('starts_at', '<', $newEndTime)
                ->where('ends_at', '>', $newStartTime)
                ->get()
                ->map(fn (Booking $existing) => $existing->asBusyBlock()),
        ];

        if ( ! $this->calculator->isBookable($settings, $tenant->timezone, CarbonImmutable::now(), $newStartTime, $busy)) {
            throw new SlotConflictException('This slot is no longer available.');
        }
    }
}
