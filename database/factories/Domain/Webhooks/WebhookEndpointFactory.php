<?php

namespace Database\Factories\Domain\Webhooks;

use Illuminate\Support\Str;
use App\Domain\Tenants\Tenant;
use App\Domain\Webhooks\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'url' => 'https://receiver.test/hooks',
            'secret' => Str::random(32),
            'events' => ['booking.created', 'booking.canceled'],
            'active' => true,
        ];
    }
}
