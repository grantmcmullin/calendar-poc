<?php

namespace Tests\Feature\Domain\Tenants;

use Tests\TestCase;
use App\Domain\Tenants\Tenant;
use App\Domain\Tenants\BookingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BookingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_belong_to_tenant_and_cast_fields(): void
    {
        $tenant = Tenant::factory()->create(['timezone' => 'America/New_York']);
        $settings = BookingSettings::factory()->for($tenant)->create([
            'available_days' => ['monday', 'wednesday'],
            'office_starts_at' => '09:00:00',
            'office_ends_at' => '17:00:00',
            'meeting_length_minutes' => 30,
        ]);

        $this->assertSame(['monday', 'wednesday'], $settings->fresh()->available_days);
        $this->assertSame('09:00:00', $settings->fresh()->office_starts_at);
        $this->assertSame(30, $settings->fresh()->meeting_length_minutes);
        $this->assertTrue($tenant->bookingSettings->is($settings));
    }

    public function test_demo_seeder_creates_tenant_with_defaults(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $tenant = Tenant::firstOrFail();
        $this->assertNotEmpty($tenant->email);
        $this->assertSame('America/New_York', $tenant->timezone);
        $this->assertSame(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], $tenant->bookingSettings->available_days);
        $this->assertSame(30, $tenant->bookingSettings->meeting_length_minutes);
    }
}
