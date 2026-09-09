<?php

namespace App\Domain\Bookings\Actions;

use App\Domain\Bookings\Booking;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Notifications\ReminderScheduler;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Webhooks\SendBookingWebhooksAction;

class CancelBookingAction
{
    public function __construct(
        protected CalendarGatewayManager $manager,
        protected ReminderScheduler $reminders,
        protected BookingNotifier $notifier,
        protected SendBookingWebhooksAction $webhooks,
    ) {
    }

    public function execute(Booking $booking, CancellationSource $source): Booking
    {
        if ($booking->status === BookingStatus::Canceled->value) {
            return $booking; // idempotent
        }

        $booking->update([
            'status' => BookingStatus::Canceled->value,
            'canceled_at' => now(),
            'cancellation_source' => $source->value,
        ]);

        $integration = $booking->tenant->integration;

        if ($source !== CancellationSource::Provider && $integration !== null && $booking->provider_event_id !== null) {
            $this->manager->for($integration)->events()->delete($integration, $booking->provider_event_id);
        }

        $this->reminders->cancelFor($booking);
        $this->notifier->sendCancellation($booking, $source);
        $this->webhooks->execute($booking->refresh(), WebhookEvent::BookingCanceled);

        return $booking;
    }
}
