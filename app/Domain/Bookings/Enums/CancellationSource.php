<?php

namespace App\Domain\Bookings\Enums;

enum CancellationSource: string
{
    case Lead = 'lead';
    case Provider = 'provider';
}
