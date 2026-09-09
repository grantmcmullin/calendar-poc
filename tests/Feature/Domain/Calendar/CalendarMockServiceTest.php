<?php

namespace Tests\Feature\Domain\Calendar;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Data\BusyBlockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;

class CalendarMockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_events_lifecycle(): void
    {
        $mock = app(CalendarMockService::class);
        $integration = Integration::factory()->create();
        $draft = new CalendarEventDraftData(
            summary: 'Consultation: Jane Doe',
            description: 'Phone: +15550001111',
            start: CarbonImmutable::parse('2026-09-14T14:00:00Z'),
            end: CarbonImmutable::parse('2026-09-14T14:30:00Z'),
            timezone: 'America/New_York',
            attendeeEmail: 'jane@example.com',
            attendeeName: 'Jane Doe',
            bookingUuid: 'uuid-1',
        );

        $event = $mock->events()->create($integration, $draft);
        $this->assertTrue($mock->events()->exists($integration, $event->id));

        $mock->events()->delete($integration, $event->id);
        $this->assertFalse($mock->events()->exists($integration, $event->id));
    }

    public function test_mock_busy_blocks_filter_to_range(): void
    {
        $mock = app(CalendarMockService::class);
        $mock->busy = [new BusyBlockData(CarbonImmutable::parse('2026-09-14T10:00:00Z'), CarbonImmutable::parse('2026-09-14T11:00:00Z'))];

        $inRange = $mock->availability()->busyBlocks(Integration::factory()->create(), CarbonImmutable::parse('2026-09-14T00:00:00Z'), CarbonImmutable::parse('2026-09-15T00:00:00Z'));
        $outOfRange = $mock->availability()->busyBlocks(Integration::factory()->create(), CarbonImmutable::parse('2026-09-16T00:00:00Z'), CarbonImmutable::parse('2026-09-17T00:00:00Z'));

        $this->assertCount(1, $inRange);
        $this->assertCount(0, $outOfRange);
    }

    public function test_mock_failure_flags_throw(): void
    {
        $mock = app(CalendarMockService::class);
        $mock->failProbe = true;
        $this->expectException(ProviderApiFailedException::class);
        $mock->availability()->busyBlocks(Integration::factory()->create(), CarbonImmutable::now(), CarbonImmutable::now()->addHour());
    }
}
