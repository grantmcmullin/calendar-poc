<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use App\Domain\Bookings\Booking;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\BookingResource;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Bookings\Actions\CancelBookingAction;
use App\Domain\Bookings\Actions\RescheduleBookingAction;

class ManageBookingController extends Controller
{
    public function show(string $token): mixed
    {
        $booking = $this->booking($token);

        if (request()->expectsJson()) {
            return new BookingResource($booking);
        }

        abort(501); // blade page arrives in Task 17
    }

    public function cancel(string $token, CancelBookingAction $action): JsonResponse
    {
        $booking = $action->execute($this->booking($token), CancellationSource::Lead);

        return response()->json(['status' => $booking->status]);
    }

    public function reschedule(string $token, Request $request, RescheduleBookingAction $action): JsonResponse
    {
        $request->validate(['start_time' => ['required', 'date']]);

        $new = $action->execute($this->booking($token), CarbonImmutable::parse($request->input('start_time'))->utc());

        return response()->json([
            'uuid' => $new->uuid,
            'start_time' => $new->starts_at->toIso8601ZuluString(),
            'manage_url' => route('manage.show', $new->manage_token),
        ]);
    }

    private function booking(string $token): Booking
    {
        return Booking::where('manage_token', $token)->firstOrFail();
    }
}
