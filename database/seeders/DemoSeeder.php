<?php

namespace Database\Seeders;

use Illuminate\Support\Str;
use App\Domain\Tenants\Tenant;
use Illuminate\Database\Seeder;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Webhooks\WebhookEndpoint;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['email' => 'tenant@example.com'],
            ['name' => 'Demo Law Firm', 'timezone' => 'America/New_York'],
        );

        BookingSettings::firstOrCreate(['tenant_id' => $tenant->id], [
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'office_starts_at' => '09:00:00',
            'office_ends_at' => '17:00:00',
            'meeting_length_minutes' => 30,
        ]);

        WebhookEndpoint::firstOrCreate(['tenant_id' => $tenant->id, 'url' => route('demo.webhook-sink')], [
            'secret' => Str::random(32),
            'events' => ['booking.created', 'booking.canceled'],
            'active' => true,
        ]);
    }
}
