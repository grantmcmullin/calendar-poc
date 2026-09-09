<?php

namespace App\Domain\Webhooks;

use App\Domain\Bookings\Booking;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Webhooks\Jobs\SendWebhookJob;

class SendBookingWebhooksAction
{
    public function execute(Booking $booking, WebhookEvent $event): void
    {
        $payload = BookingWebhookPayload::for($booking, $event);

        $booking->tenant->webhookEndpoints()
            ->where('active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => in_array($event->value, $endpoint->events, true))
            ->each(function (WebhookEndpoint $endpoint) use ($event, $payload): void {
                $delivery = WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event' => $event->value,
                    'payload' => $payload,
                ]);

                SendWebhookJob::dispatch($delivery);
            });
    }
}
