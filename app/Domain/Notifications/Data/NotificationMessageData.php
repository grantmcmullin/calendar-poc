<?php

namespace App\Domain\Notifications\Data;

final class NotificationMessageData
{
    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly ?string $recipientPhone,
        public readonly string $subject,
        /** @var array<int, string> */
        public readonly array $lines,
    ) {
    }
}
