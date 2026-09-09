<?php

namespace Database\Factories\Domain\Integrations;

use App\Domain\Tenants\Tenant;
use App\Domain\Integrations\Integration;
use App\Domain\Integrations\Enums\IntegrationType;
use Illuminate\Database\Eloquent\Factories\Factory;

class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => IntegrationType::Google->value,
            'api_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'data' => ['account_email' => 'tenant-cal@example.com', 'calendar_id' => 'primary'],
        ];
    }
}
