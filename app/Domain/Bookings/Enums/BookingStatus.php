<?php

namespace App\Domain\Bookings\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Canceled = 'canceled';
}
