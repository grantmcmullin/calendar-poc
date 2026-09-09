<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Data\BusyBlockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Calendar\Services\Mock\CalendarMockService;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        $this->travelTo('2026-09-10T00:00:00Z');
        $this->tenant = Tenant::factory()->create(['timezone' => 'America/New_York']);
        BookingSettings::factory()->for($this->tenant)->create();
        Integration::factory()->for($this->tenant)->create();
    }

    public function test_returns_available_slots_shaped_like_calendly(): void
    {
        $response = $this->getJson("/api/v1/tenants/{$this->tenant->id}/availability?from=2026-09-14&to=2026-09-14");

        $response->assertOk()
            ->assertJsonPath('collection.0.status', 'available')
            ->assertJsonPath('collection.0.start_time', '2026-09-14T13:00:00Z')
            ->assertJsonPath('collection.0.end_time', '2026-09-14T13:30:00Z')
            ->assertJsonCount(16, 'collection');
    }

    public function test_provider_busy_and_own_confirmed_bookings_are_excluded(): void
    {
        app(CalendarMockService::class)->busy = [
            new BusyBlockData(CarbonImmutable::parse('2026-09-14T13:00:00Z'), CarbonImmutable::parse('2026-09-14T13:30:00Z')),
        ];
        Booking::factory()->for($this->tenant)->create([
            'starts_at' => '2026-09-14T14:00:00Z', 'ends_at' => '2026-09-14T14:30:00Z',
        ]);
        Booking::factory()->for($this->tenant)->canceled()->create([
            'starts_at' => '2026-09-14T15:00:00Z', 'ends_at' => '2026-09-14T15:30:00Z',
        ]);

        $starts = collect($this->getJson("/api/v1/tenants/{$this->tenant->id}/availability?from=2026-09-14&to=2026-09-14")
            ->json('collection'))->pluck('start_time');

        $this->assertNotContains('2026-09-14T13:00:00Z', $starts); // provider busy
        $this->assertNotContains('2026-09-14T14:00:00Z', $starts); // own confirmed
        $this->assertContains('2026-09-14T15:00:00Z', $starts);    // canceled booking frees the slot
    }

    public function test_range_over_seven_days_is_rejected(): void
    {
        $this->getJson("/api/v1/tenants/{$this->tenant->id}/availability?from=2026-09-14&to=2026-09-21")
            ->assertStatus(422);
    }

    public function test_missing_integration_is_rejected(): void
    {
        $bare = Tenant::factory()->create();
        BookingSettings::factory()->for($bare)->create();

        $this->getJson("/api/v1/tenants/{$bare->id}/availability?from=2026-09-14&to=2026-09-14")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No calendar integration is connected for this tenant.');
    }

    public function test_missing_booking_settings_is_rejected(): void
    {
        $bare = Tenant::factory()->create();
        Integration::factory()->for($bare)->create();

        $this->getJson("/api/v1/tenants/{$bare->id}/availability?from=2026-09-14&to=2026-09-14")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Booking settings are not configured for this tenant.');
    }
}
