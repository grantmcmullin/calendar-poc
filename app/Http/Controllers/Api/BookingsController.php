<?php

namespace App\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Domain\Bookings\Data\InviteeData;
use App\Http\Requests\StoreBookingRequest;
use App\Domain\Bookings\Actions\CreateBookingAction;

class BookingsController extends Controller
{
    public function store(StoreBookingRequest $request, Tenant $tenant, CreateBookingAction $action): JsonResponse
    {
        $booking = $action->execute(
            $tenant,
            CarbonImmutable::parse($request->input('start_time'))->utc(),
            new InviteeData(
                firstName: $request->input('invitee.first_name'),
                lastName: $request->input('invitee.last_name'),
                email: $request->input('invitee.email'),
                phone: $request->input('invitee.phone'),
                timezone: $request->input('invitee.timezone'),
            ),
            $request->input('tracking', []),
        );

        return (new BookingResource($booking))->response()->setStatusCode(201);
    }

    public function show(Booking $booking): BookingResource
    {
        return new BookingResource($booking);
    }
}
