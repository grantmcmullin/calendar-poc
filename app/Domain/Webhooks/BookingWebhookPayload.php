<?php

namespace App\Domain\Webhooks;

use App\Domain\Bookings\Booking;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Bookings\Enums\BookingStatus;

class BookingWebhookPayload
{
    public static function for(Booking $booking, WebhookEvent $event): array
    {
        $integrationId = $booking->tenant->integration?->id ?? 0;

        return [
            'event' => $event->value,
            'created_by' => sprintf('tenant:%d:integration:%d', $booking->tenant_id, $integrationId),
            'payload' => [
                'booking' => [
                    'uuid' => $booking->uuid,
                    'uri' => route('api.bookings.show', $booking->uuid),
                    'start_time' => $booking->starts_at->toIso8601ZuluString(),
                    'end_time' => $booking->ends_at->toIso8601ZuluString(),
                    'status' => $booking->status,
                    'provider' => $booking->provider,
                    'rescheduled_from' => $booking->rescheduled_from_booking_id
                        ? Booking::find($booking->rescheduled_from_booking_id)?->uuid
                        : null,
                    'cancellation' => $booking->status === BookingStatus::Canceled->value
                        ? ['source' => $booking->cancellation_source, 'canceled_at' => $booking->canceled_at?->toIso8601ZuluString()]
                        : null,
                ],
                'invitee' => [
                    'first_name' => $booking->lead_first_name,
                    'last_name' => $booking->lead_last_name,
                    'email' => $booking->lead_email,
                    'phone' => $booking->lead_phone,
                    'timezone' => $booking->lead_timezone,
                ],
                'tracking' => $booking->tracking ?? [],
            ],
        ];
    }
}
