<?php

namespace Tests\Feature\Api;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use App\Domain\Notifications\Reminder;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Integrations\Integration;
use App\Domain\Webhooks\WebhookEndpoint;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Webhooks\Jobs\SendWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Notifications\Mail\BookingNotificationMail;

class CreateBookingTest extends TestCase
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
        WebhookEndpoint::factory()->for($this->tenant)->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'start_time' => '2026-09-14T14:00:00Z', // Monday 10:00 EDT — on grid
            'invitee' => [
                'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
                'phone' => '+15550001111', 'timezone' => 'America/Chicago',
            ],
            'tracking' => ['utm_source' => 'ULH', 'utm_content' => 'lead-token-abc'],
        ], $overrides);
    }

    public function test_happy_path_books_creates_event_reminders_mails_and_webhook(): void
    {
        Mail::fake();
        Queue::fake();

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload());

        $response->assertCreated()
            ->assertJsonPath('start_time', '2026-09-14T14:00:00Z')
            ->assertJsonPath('status', 'confirmed');

        $booking = Booking::firstOrFail();
        $this->assertSame('mock-event-'.$booking->uuid, $booking->provider_event_id);
        $this->assertSame(['utm_source' => 'ULH', 'utm_content' => 'lead-token-abc'], $booking->tracking);
        $this->assertStringContainsString($booking->manage_token, $response->json('reschedule_url'));
        $this->assertSame(4, Reminder::count());                       // 2 offsets × 2 recipients
        Mail::assertSent(BookingNotificationMail::class, 2);           // confirmations
        Queue::assertPushed(SendWebhookJob::class, 1);                 // booking.created
        $mock = app(CalendarMockService::class);
        $this->assertSame('Consultation: Jane Doe', $mock->createdEvents[$booking->provider_event_id]->summary);
    }

    public function test_double_booking_same_slot_returns_409(): void
    {
        Mail::fake();
        Queue::fake();
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertCreated();

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertStatus(409);
        $this->assertSame(1, Booking::count());
    }

    public function test_provider_busy_at_booking_time_returns_409(): void
    {
        app(CalendarMockService::class)->busy = [
            new BusyBlockData(CarbonImmutable::parse('2026-09-14T14:00:00Z'), CarbonImmutable::parse('2026-09-14T14:30:00Z')),
        ];

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertStatus(409);
        $this->assertSame(0, Booking::count());
    }

    public function test_probe_error_returns_502_and_creates_nothing(): void
    {
        app(CalendarMockService::class)->failProbe = true;

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertStatus(502);
        $this->assertSame(0, Booking::count());
    }

    public function test_provider_create_failure_returns_502_and_rolls_back_row(): void
    {
        Mail::fake();
        Queue::fake();
        app(CalendarMockService::class)->failCreate = true;

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload())->assertStatus(502);
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Reminder::count());
        Queue::assertNothingPushed();
    }

    public function test_off_grid_start_time_returns_409(): void
    {
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload(['start_time' => '2026-09-14T14:10:00Z']))
            ->assertStatus(409);
    }

    public function test_notifier_failure_does_not_block_webhook_dispatch(): void
    {
        Queue::fake();
        $this->mock(\App\Domain\Notifications\BookingNotifier::class, function ($mock) {
            $mock->shouldReceive('sendConfirmation')->andThrow(new \RuntimeException('mail down'));
        });

        $response = $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload());

        $response->assertCreated();
        $this->assertSame(1, Booking::count());
        Queue::assertPushed(SendWebhookJob::class, 1); // booking.created still dispatched despite mail failure
    }

    public function test_show_booking_by_uuid(): void
    {
        Mail::fake();
        Queue::fake();
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", $this->payload());
        $uuid = Booking::firstOrFail()->uuid;

        $this->getJson("/api/v1/bookings/{$uuid}")
            ->assertOk()
            ->assertJsonPath('uuid', $uuid)
            ->assertJsonPath('status', 'confirmed');
    }
}
