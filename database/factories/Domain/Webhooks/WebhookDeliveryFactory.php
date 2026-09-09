<?php

namespace Database\Factories\Domain\Webhooks;

use App\Domain\Webhooks\WebhookDelivery;
use App\Domain\Webhooks\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event' => 'booking.created',
            'payload' => ['event' => 'booking.created'],
        ];
    }
}
