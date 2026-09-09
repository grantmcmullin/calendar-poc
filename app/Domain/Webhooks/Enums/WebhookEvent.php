<?php

namespace App\Domain\Webhooks\Enums;

enum WebhookEvent: string
{
    case BookingCreated = 'booking.created';
    case BookingCanceled = 'booking.canceled';
}
