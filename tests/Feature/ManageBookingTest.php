<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use App\Domain\Notifications\Reminder;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Integrations\Integration;
use App\Domain\Webhooks\WebhookEndpoint;
use App\Domain\Webhooks\Jobs\SendWebhookJob;
use App\Domain\Bookings\Enums\CancellationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Calendar\Services\Mock\CalendarMockService;

class ManageBookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        Mail::fake();
        Queue::fake();
        $this->travelTo('2026-09-10T00:00:00Z');
        $this->tenant = Tenant::factory()->create(['timezone' => 'America/New_York']);
        BookingSettings::factory()->for($this->tenant)->create();
        Integration::factory()->for($this->tenant)->create();
        WebhookEndpoint::factory()->for($this->tenant)->create();
    }

    private function book(string $startTime = '2026-09-14T14:00:00Z'): Booking
    {
        $this->postJson("/api/v1/tenants/{$this->tenant->id}/bookings", [
            'start_time' => $startTime,
            'invitee' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'phone' => '+15550001111', 'timezone' => 'America/Chicago'],
            'tracking' => ['utm_content' => 'lead-token-abc'],
        ])->assertCreated();

        return Booking::latest('id')->firstOrFail();
    }

    public function test_lead_cancel_deletes_provider_event_clears_reminders_and_webhooks(): void
    {
        $booking = $this->book();

        $this->postJson("/manage/{$booking->manage_token}/cancel")->assertOk()->assertJsonPath('status', 'canceled');

        $booking->refresh();
        $this->assertSame('canceled', $booking->status);
        $this->assertSame('lead', $booking->cancellation_source);
        $this->assertContains($booking->provider_event_id, app(CalendarMockService::class)->deletedEventIds);
        $this->assertSame(0, Reminder::whereNull('sent_at')->count());
        Queue::assertPushed(SendWebhookJob::class, 2); // created + canceled
    }

    public function test_cancel_is_idempotent(): void
    {
        $booking = $this->book();
        $this->postJson("/manage/{$booking->manage_token}/cancel")->assertOk();
        $this->postJson("/manage/{$booking->manage_token}/cancel")->assertOk();

        Queue::assertPushed(SendWebhookJob::class, 2); // no extra webhook on second cancel
    }

    public function test_provider_sourced_cancel_skips_provider_delete(): void
    {
        $booking = $this->book();
        $mock = app(CalendarMockService::class);
        $before = count($mock->deletedEventIds);

        app(\App\Domain\Bookings\Actions\CancelBookingAction::class)->execute($booking, CancellationSource::Provider);

        $this->assertCount($before, $mock->deletedEventIds); // no delete call
        $this->assertSame('provider', $booking->fresh()->cancellation_source);
    }

    public function test_reschedule_cancels_old_creates_linked_new_and_returns_new_manage_url(): void
    {
        $booking = $this->book();

        $response = $this->postJson("/manage/{$booking->manage_token}/reschedule", ['start_time' => '2026-09-15T14:00:00Z'])
            ->assertOk();

        $new = Booking::where('uuid', $response->json('uuid'))->firstOrFail();
        $this->assertSame('canceled', $booking->fresh()->status);
        $this->assertSame($booking->id, $new->rescheduled_from_booking_id);
        $this->assertSame('lead-token-abc', $new->tracking['utm_content']);
        $this->assertStringContainsString($new->manage_token, $response->json('manage_url'));
        Queue::assertPushed(SendWebhookJob::class, 3); // created + canceled + created
    }

    public function test_invalid_manage_token_is_404(): void
    {
        $this->postJson('/manage/not-a-real-token/cancel')->assertNotFound();
    }
}
