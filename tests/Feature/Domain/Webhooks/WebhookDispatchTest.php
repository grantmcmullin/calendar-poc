<?php

namespace Tests\Feature\Domain\Webhooks;

use Tests\TestCase;
use App\Domain\Bookings\Booking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use App\Domain\Webhooks\WebhookDelivery;
use App\Domain\Webhooks\WebhookEndpoint;
use App\Domain\Webhooks\WebhookSignature;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Webhooks\Jobs\SendWebhookJob;
use App\Domain\Webhooks\BookingWebhookPayload;
use App\Domain\Webhooks\SendBookingWebhooksAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_matches_spec_envelope(): void
    {
        $booking = Booking::factory()->create(['tracking' => ['utm_content' => 'lead-token-abc']]);

        $payload = BookingWebhookPayload::for($booking, WebhookEvent::BookingCreated);

        $this->assertSame('booking.created', $payload['event']);
        $this->assertSame("tenant:{$booking->tenant_id}", explode(':integration', $payload['created_by'])[0]);
        $this->assertSame($booking->uuid, $payload['payload']['booking']['uuid']);
        $this->assertSame($booking->starts_at->toIso8601ZuluString(), $payload['payload']['booking']['start_time']);
        $this->assertSame('lead-token-abc', $payload['payload']['tracking']['utm_content']);
        $this->assertSame('Jane', $payload['payload']['invitee']['first_name']);
        $this->assertNull($payload['payload']['booking']['cancellation']);
    }

    public function test_action_creates_delivery_rows_and_dispatches_jobs_for_matching_endpoints(): void
    {
        Queue::fake();
        $booking = Booking::factory()->create();
        WebhookEndpoint::factory()->for($booking->tenant)->create(['events' => ['booking.created', 'booking.canceled']]);
        WebhookEndpoint::factory()->for($booking->tenant)->create(['events' => ['booking.canceled']]); // no match
        WebhookEndpoint::factory()->for($booking->tenant)->create(['active' => false]);                 // inactive

        app(SendBookingWebhooksAction::class)->execute($booking, WebhookEvent::BookingCreated);

        $this->assertSame(1, WebhookDelivery::count());
        Queue::assertPushed(SendWebhookJob::class, 1);
    }

    public function test_job_signs_and_posts_and_records_response(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['ok' => true], 200)]);
        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://receiver.test/hooks', 'secret' => 'shh']);
        $delivery = WebhookDelivery::factory()->for($endpoint, 'endpoint')->create();

        (new SendWebhookJob($delivery))->handle();

        Http::assertSent(function ($request) {
            $header = $request->header('Calendar-Service-Signature')[0] ?? '';

            return $request->url() === 'https://receiver.test/hooks'
                && WebhookSignature::verify('shh', $request->body(), $header);
        });
        $delivery->refresh();
        $this->assertSame(200, $delivery->response_status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_job_releases_for_retry_and_records_failure_response(): void
    {
        Http::fake(['receiver.test/*' => Http::response(['error' => 'boom'], 500)]);
        $endpoint = WebhookEndpoint::factory()->create(['url' => 'https://receiver.test/hooks', 'secret' => 'shh']);
        $delivery = WebhookDelivery::factory()->for($endpoint, 'endpoint')->create();

        $job = (new SendWebhookJob($delivery))->withFakeQueueInteractions();
        $job->handle();

        Http::assertSent(function ($request) {
            $header = $request->header('Calendar-Service-Signature')[0] ?? '';

            return $request->url() === 'https://receiver.test/hooks'
                && WebhookSignature::verify('shh', $request->body(), $header);
        });
        $delivery->refresh();
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->signature);
        $this->assertSame(500, $delivery->response_status);
        $this->assertNull($delivery->delivered_at);
        $job->assertReleased(delay: 10);
    }

    public function test_sink_route_verifies_signature(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $endpoint = WebhookEndpoint::firstOrFail();
        $body = json_encode(['event' => 'booking.created']);
        $goodHeader = WebhookSignature::header($endpoint->secret, $body, time());

        $this->call('POST', '/demo/webhook-sink', server: ['HTTP_CALENDAR_SERVICE_SIGNATURE' => $goodHeader, 'CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertNoContent();

        $this->call('POST', '/demo/webhook-sink', server: ['HTTP_CALENDAR_SERVICE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertStatus(400);
    }
}
