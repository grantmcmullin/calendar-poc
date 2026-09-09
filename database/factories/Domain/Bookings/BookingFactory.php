<?php

namespace Database\Factories\Domain\Bookings;

use Illuminate\Support\Str;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Bookings\Enums\CancellationSource;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'provider' => 'google',
            'provider_event_id' => 'evt-'.fake()->uuid(),
            'lead_first_name' => 'Jane', 'lead_last_name' => 'Doe',
            'lead_email' => 'jane@example.com', 'lead_phone' => '+15550001111',
            'lead_timezone' => 'America/Chicago',
            'tracking' => ['utm_source' => 'ULH', 'utm_content' => 'encrypted-lead-token'],
            'starts_at' => now()->addDay()->setTime(14, 0),
            'ends_at' => now()->addDay()->setTime(14, 30),
            'status' => BookingStatus::Confirmed->value,
            'manage_token' => Str::random(64),
        ];
    }

    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Canceled->value,
            'canceled_at' => now(),
            'cancellation_source' => CancellationSource::Lead->value,
        ]);
    }
}
