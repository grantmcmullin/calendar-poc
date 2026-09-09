<?php

namespace Database\Factories\Domain\Tenants;

use App\Domain\Tenants\Tenant;
use App\Domain\Tenants\BookingSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingSettingsFactory extends Factory
{
    protected $model = BookingSettings::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'office_starts_at' => '09:00:00',
            'office_ends_at' => '17:00:00',
            'meeting_length_minutes' => 30,
        ];
    }

    public function withoutTenant(): static
    {
        return $this->state([
            'tenant_id' => 1,
        ]);
    }
}
