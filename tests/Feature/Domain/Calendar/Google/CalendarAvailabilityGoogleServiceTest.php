<?php

namespace Tests\Feature\Domain\Calendar\Google;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use App\Domain\Integrations\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Services\Google\CalendarAvailabilityGoogleService;

class CalendarAvailabilityGoogleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_busy_blocks_are_parsed_from_freebusy_response(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => [
                ['start' => '2026-09-14T14:00:00Z', 'end' => '2026-09-14T15:00:00Z'],
            ]]],
        ])]);
        $integration = Integration::factory()->create();

        $blocks = app(CalendarAvailabilityGoogleService::class)->busyBlocks(
            $integration,
            CarbonImmutable::parse('2026-09-14T00:00:00Z'),
            CarbonImmutable::parse('2026-09-15T00:00:00Z'),
        );

        $this->assertCount(1, $blocks);
        $this->assertTrue($blocks[0]->start->equalTo('2026-09-14T14:00:00Z'));
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r['items'] === [['id' => 'primary']]
            && $r['timeMin'] === '2026-09-14T00:00:00+00:00');
    }

    public function test_freebusy_calendar_errors_throw(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/freeBusy' => Http::response([
            'calendars' => ['primary' => ['busy' => [], 'errors' => [['reason' => 'notFound']]]],
        ])]);

        $this->expectException(ProviderApiFailedException::class);
        app(CalendarAvailabilityGoogleService::class)->busyBlocks(Integration::factory()->create(), CarbonImmutable::now(), CarbonImmutable::now()->addDay());
    }

    public function test_401_triggers_token_refresh_and_replay(): void
    {
        Http::fakeSequence('www.googleapis.com/calendar/v3/freeBusy')
            ->push(['error' => 'unauthorized'], 401)
            ->push(['calendars' => ['primary' => ['busy' => []]]], 200);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-new', 'expires_in' => 3599])]);
        $integration = Integration::factory()->create(['api_token' => 'at-stale', 'refresh_token' => 'rt-1', 'expires_at' => now()->addHour()]);

        $blocks = app(CalendarAvailabilityGoogleService::class)->busyBlocks($integration, CarbonImmutable::now(), CarbonImmutable::now()->addDay());

        $this->assertSame([], $blocks);
        $this->assertSame('at-new', $integration->fresh()->api_token);
    }
}
