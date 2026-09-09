<?php

namespace Tests\Feature\Domain\Calendar\Google;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use App\Domain\Integrations\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Services\Google\CalendarEventGoogleService;

class CalendarEventGoogleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_sends_spec_payload_and_returns_event_data(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response([
            'id' => 'evt-1', 'htmlLink' => 'https://calendar.google.com/event?eid=abc',
        ])]);
        $integration = Integration::factory()->create();
        $draft = new CalendarEventDraftData(
            summary: 'Consultation: Jane Doe',
            description: "Booked via ULH.\nPhone: +15550001111",
            start: CarbonImmutable::parse('2026-09-14T14:00:00Z'),
            end: CarbonImmutable::parse('2026-09-14T14:30:00Z'),
            timezone: 'America/New_York',
            attendeeEmail: 'jane@example.com',
            attendeeName: 'Jane Doe',
            bookingUuid: 'uuid-1',
        );

        $event = app(CalendarEventGoogleService::class)->create($integration, $draft);

        $this->assertSame('evt-1', $event->id);
        Http::assertSent(function ($r) {
            return str_contains($r->url(), 'sendUpdates=all')
                && $r['attendees'] === [['email' => 'jane@example.com', 'displayName' => 'Jane Doe']]
                && $r['extendedProperties'] === ['private' => ['booking_uuid' => 'uuid-1']]
                && $r['start'] === ['dateTime' => '2026-09-14T14:00:00+00:00', 'timeZone' => 'America/New_York'];
        });
    }

    public function test_delete_treats_404_and_410_as_already_gone(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events/evt-gone?*' => Http::response(null, 410)]);
        app(CalendarEventGoogleService::class)->delete(Integration::factory()->create(), 'evt-gone');
        $this->assertTrue(true); // no exception
    }

    public function test_exists_is_false_for_404_and_for_cancelled_status(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events/evt-404' => Http::response(null, 404),
            'www.googleapis.com/calendar/v3/calendars/primary/events/evt-cancelled' => Http::response(['id' => 'evt-cancelled', 'status' => 'cancelled']),
            'www.googleapis.com/calendar/v3/calendars/primary/events/evt-live' => Http::response(['id' => 'evt-live', 'status' => 'confirmed']),
        ]);
        $service = app(CalendarEventGoogleService::class);
        $integration = Integration::factory()->create();

        $this->assertFalse($service->exists($integration, 'evt-404'));
        $this->assertFalse($service->exists($integration, 'evt-cancelled'));
        $this->assertTrue($service->exists($integration, 'evt-live'));
    }
}
