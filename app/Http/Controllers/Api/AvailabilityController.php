<?php

namespace App\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use App\Domain\Bookings\AvailabilityCalculator;
use App\Domain\Calendar\CalendarGatewayManager;

class AvailabilityController extends Controller
{
    public function __invoke(AvailabilityRequest $request, Tenant $tenant, CalendarGatewayManager $manager, AvailabilityCalculator $calculator): JsonResponse
    {
        $integration = $tenant->integration;

        if ($integration === null) {
            abort(422, 'No calendar integration is connected for this tenant.');
        }

        $settings = $tenant->bookingSettings;

        if ($settings === null) {
            abort(422, 'Booking settings are not configured for this tenant.');
        }

        $timezone = $tenant->timezone;
        $fromUtc = CarbonImmutable::parse($request->input('from'), $timezone)->startOfDay()->utc();
        $toUtc = CarbonImmutable::parse($request->input('to'), $timezone)->endOfDay()->utc();

        $busy = $manager->for($integration)->availability()->busyBlocks($integration, $fromUtc, $toUtc);

        $ownBookings = Booking::query()->confirmed()
            ->where('tenant_id', $tenant->id)
            ->where('starts_at', '<', $toUtc)
            ->where('ends_at', '>', $fromUtc)
            ->get()
            ->map(fn (Booking $booking) => $booking->asBusyBlock())
            ->all();

        $slots = $calculator->slots(
            $settings,
            $timezone,
            CarbonImmutable::now(),
            $request->input('from'),
            $request->input('to'),
            [...$busy, ...$ownBookings],
        );

        return response()->json([
            'collection' => array_map(fn (CarbonImmutable $start) => [
                'status' => 'available',
                'start_time' => $start->toIso8601ZuluString(),
                'end_time' => $start->addMinutes($settings->meeting_length_minutes)->toIso8601ZuluString(),
            ], $slots),
        ]);
    }
}
