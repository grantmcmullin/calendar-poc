<?php

namespace Tests\Feature\Domain\Bookings;

use Tests\TestCase;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use App\Domain\Integrations\Integration;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Bookings\Jobs\ReconcileBookingsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Bookings\Actions\CancelBookingAction;
use App\Domain\Calendar\Services\Mock\CalendarMockService;

class ReconcileBookingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        Mail::fake();
        Queue::fake();
    }

    private function bookingWithLiveEvent(Tenant $tenant): Booking
    {
        $booking = Booking::factory()->for($tenant)->create(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(30)]);
        $mock = app(CalendarMockService::class);
        // exists() only checks the key; DTOs are final so build a real (cheap) draft rather than mocking.
        $mock->createdEvents[$booking->provider_event_id] = new \App\Domain\Calendar\Data\CalendarEventDraftData(
            summary: 'x',
            description: 'x',
            start: \Carbon\CarbonImmutable::parse($booking->starts_at),
            end: \Carbon\CarbonImmutable::parse($booking->ends_at),
            timezone: 'UTC',
            attendeeEmail: 'x@example.com',
            attendeeName: 'x',
            bookingUuid: $booking->uuid,
        );

        return $booking;
    }

    public function test_gone_provider_event_cancels_booking_with_provider_source(): void
    {
        $tenant = Tenant::factory()->has(Integration::factory())->create();
        $live = $this->bookingWithLiveEvent($tenant);
        $gone = $this->bookingWithLiveEvent($tenant);
        app(CalendarMockService::class)->deletedEventIds[] = $gone->provider_event_id;

        (new ReconcileBookingsJob())->handle();

        $this->assertSame('canceled', $gone->fresh()->status);
        $this->assertSame('provider', $gone->fresh()->cancellation_source);
        $this->assertSame('confirmed', $live->fresh()->status);
    }

    public function test_provider_error_leaves_bookings_untouched(): void
    {
        $tenant = Tenant::factory()->has(Integration::factory())->create();
        $booking = $this->bookingWithLiveEvent($tenant);
        // Simulate probe error by making exists() throw: reuse failProbe? exists doesn't use it —
        // point the booking at a tenant whose gateway throws instead:
        app(CalendarMockService::class)->failExists = true;

        (new ReconcileBookingsJob())->handle();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_cancellation_failure_for_one_booking_does_not_abort_reconciliation_of_others(): void
    {
        $tenant = Tenant::factory()->has(Integration::factory())->create();
        $first = $this->bookingWithLiveEvent($tenant);
        $second = $this->bookingWithLiveEvent($tenant);
        app(CalendarMockService::class)->deletedEventIds[] = $first->provider_event_id;
        app(CalendarMockService::class)->deletedEventIds[] = $second->provider_event_id;

        // Spy on the real action: throw for the first booking it is asked to cancel
        // (simulating e.g. a synchronous mail transport failure) and delegate to the
        // real implementation for every subsequent booking.
        $real = $this->app->make(CancelBookingAction::class);
        $attempted = [];
        $this->app->bind(CancelBookingAction::class, function () use ($real, &$attempted) {
            return new class($real, $attempted) {
                private array $attempted;

                public function __construct(private CancelBookingAction $real, array &$attempted)
                {
                    $this->attempted = &$attempted;
                }

                public function execute(Booking $booking, CancellationSource $source): Booking
                {
                    $this->attempted[] = $booking->id;

                    if (count($this->attempted) === 1) {
                        throw new \RuntimeException('Simulated mail transport failure');
                    }

                    return $this->real->execute($booking, $source);
                }
            };
        });

        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) use ($first) {
            return $message === '[Reconcile] Failed to cancel booking with gone provider event; continuing'
                && $context['booking_id'] === $first->id
                && $context['message'] === 'Simulated mail transport failure';
        });

        (new ReconcileBookingsJob())->handle();

        $this->assertSame([$first->id, $second->id], $attempted);
        $this->assertSame('confirmed', $first->fresh()->status);
        $this->assertSame('canceled', $second->fresh()->status);
        $this->assertSame('provider', $second->fresh()->cancellation_source);
    }

    public function test_past_and_eventless_bookings_are_skipped(): void
    {
        $tenant = Tenant::factory()->has(Integration::factory())->create();
        Booking::factory()->for($tenant)->create(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addMinutes(30)]);
        Booking::factory()->for($tenant)->create(['provider_event_id' => null, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30)]);

        (new ReconcileBookingsJob())->handle();

        $this->assertSame(2, Booking::confirmed()->count());
    }
}
