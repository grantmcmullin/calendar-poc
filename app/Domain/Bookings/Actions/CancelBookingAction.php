<?php

namespace App\Domain\Bookings\Actions;

use Throwable;
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
            try {
                $this->manager->for($integration)->events()->delete($integration, $booking->provider_event_id);
            } catch (Throwable $exception) {
                // A stale Google event is less harmful than losing the booking.canceled webhook
                // and reminder cleanup below — keep going instead of aborting the cancellation.
                logger()->error('[Bookings] Failed to delete provider event on cancel', [
                    'booking_id' => $booking->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->reminders->cancelFor($booking);

        // Webhook before mail: webhooks are the durable, retried channel — a synchronous mail
        // failure must not swallow the UA-app's booking.canceled contract (see task-14 review).
        $this->webhooks->execute($booking->refresh(), WebhookEvent::BookingCanceled);

        try {
            $this->notifier->sendCancellation($booking, $source);
        } catch (Throwable $exception) {
            logger()->error('[Bookings] Failed to send cancellation notification', [
                'booking_id' => $booking->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return $booking;
    }
}
