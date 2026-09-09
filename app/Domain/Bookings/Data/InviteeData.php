<?php

namespace App\Domain\Bookings\Data;

final class InviteeData
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $timezone,
    ) {
    }
}
