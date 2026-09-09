<?php

namespace Tests\Unit\Domain\Bookings;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Domain\Tenants\BookingSettings;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Bookings\AvailabilityCalculator;

class AvailabilityCalculatorTest extends TestCase
{
    private AvailabilityCalculator $calculator;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AvailabilityCalculator();
        $this->now = CarbonImmutable::parse('2026-09-10T00:00:00Z'); // Thursday
    }

    private function settings(array $overrides = []): BookingSettings
    {
        return BookingSettings::factory()->withoutTenant()->make(array_merge([
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'office_starts_at' => '09:00:00',
            'office_ends_at' => '17:00:00',
            'meeting_length_minutes' => 30,
        ], $overrides));
    }

    public function test_generates_grid_within_office_hours_in_tenant_timezone(): void
    {
        // Monday 2026-09-14, America/New_York (UTC-4): 09:00–17:00 → 16 half-hour slots
        $slots = $this->calculator->slots($this->settings(), 'America/New_York', $this->now, '2026-09-14', '2026-09-14', []);

        $this->assertCount(16, $slots);
        $this->assertTrue($slots[0]->equalTo('2026-09-14T13:00:00Z'));      // 09:00 EDT
        $this->assertTrue(end($slots)->equalTo('2026-09-14T20:30:00Z'));    // 16:30 EDT — ends exactly at close
    }

    public function test_unavailable_weekday_yields_no_slots(): void
    {
        $slots = $this->calculator->slots($this->settings(), 'America/New_York', $this->now, '2026-09-13', '2026-09-13', []); // Sunday
        $this->assertSame([], $slots);
    }

    public function test_slot_must_end_by_close(): void
    {
        // 60-minute meetings, close 17:00 → last slot starts 16:00
        $slots = $this->calculator->slots($this->settings(['meeting_length_minutes' => 60]), 'America/New_York', $this->now, '2026-09-14', '2026-09-14', []);
        $this->assertTrue(end($slots)->equalTo('2026-09-14T20:00:00Z')); // 16:00 EDT
    }

    public function test_past_slots_are_dropped(): void
    {
        $now = CarbonImmutable::parse('2026-09-14T15:00:00Z'); // 11:00 EDT that same Monday
        $slots = $this->calculator->slots($this->settings(), 'America/New_York', $now, '2026-09-14', '2026-09-14', []);
        $this->assertTrue($slots[0]->equalTo('2026-09-14T15:30:00Z')); // 11:30 EDT is first future slot
    }

    public function test_busy_overlap_kills_slot_but_touching_edges_do_not(): void
    {
        // Busy 14:00–15:00Z (10:00–11:00 EDT) kills the 10:00 and 10:30 slots only.
        $busy = [new BusyBlockData(CarbonImmutable::parse('2026-09-14T14:00:00Z'), CarbonImmutable::parse('2026-09-14T15:00:00Z'))];
        $slots = $this->calculator->slots($this->settings(), 'America/New_York', $this->now, '2026-09-14', '2026-09-14', $busy);

        $starts = array_map(fn ($s) => $s->toIso8601ZuluString(), $slots);
        $this->assertNotContains('2026-09-14T14:00:00Z', $starts);
        $this->assertNotContains('2026-09-14T14:30:00Z', $starts);
        $this->assertContains('2026-09-14T13:30:00Z', $starts); // ends exactly 14:00 — touching, not overlap
        $this->assertContains('2026-09-14T15:00:00Z', $starts); // starts exactly at busy end
    }

    public function test_dst_fall_back_day_yields_wall_clock_hours(): void
    {
        // US DST ends 2026-11-01 (America/New_York). 09:00–17:00 wall clock = 16 slots regardless.
        $slots = $this->calculator->slots($this->settings(['available_days' => ['sunday']]), 'America/New_York', $this->now, '2026-11-01', '2026-11-01', []);
        $this->assertCount(16, $slots);
        $this->assertTrue($slots[0]->equalTo('2026-11-01T14:00:00Z')); // 09:00 EST (UTC-5 after fall-back)
    }

    public function test_is_bookable_matches_grid_and_rejects_off_grid_and_busy(): void
    {
        $settings = $this->settings();
        $tz = 'America/New_York';

        $this->assertTrue($this->calculator->isBookable($settings, $tz, $this->now, CarbonImmutable::parse('2026-09-14T14:00:00Z'), []));
        $this->assertFalse($this->calculator->isBookable($settings, $tz, $this->now, CarbonImmutable::parse('2026-09-14T14:10:00Z'), [])); // off-grid
        $this->assertFalse($this->calculator->isBookable($settings, $tz, $this->now, CarbonImmutable::parse('2026-09-13T14:00:00Z'), [])); // Sunday
        $busy = [new BusyBlockData(CarbonImmutable::parse('2026-09-14T14:00:00Z'), CarbonImmutable::parse('2026-09-14T14:30:00Z'))];
        $this->assertFalse($this->calculator->isBookable($settings, $tz, $this->now, CarbonImmutable::parse('2026-09-14T14:00:00Z'), $busy));
    }
}
