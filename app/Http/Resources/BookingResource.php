<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Domain\Bookings\Booking;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'uri' => route('api.bookings.show', $this->uuid),
            'start_time' => $this->starts_at->toIso8601ZuluString(),
            'end_time' => $this->ends_at->toIso8601ZuluString(),
            'status' => $this->status,
            'provider' => $this->provider,
            'reschedule_url' => route('manage.show', $this->manage_token),
            'cancel_url' => route('manage.show', $this->manage_token),
        ];
    }
}
