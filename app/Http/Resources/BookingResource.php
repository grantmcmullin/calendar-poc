<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
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
