<?php

namespace App\Domain\Tenants\Enums;

enum MeetingLength: int
{
    case Fifteen = 15;
    case Thirty = 30;
    case FortyFive = 45;
    case Sixty = 60;

    /** @return array<int, int> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
