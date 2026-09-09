<?php

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Data\NotificationMessageData;
use App\Domain\Notifications\Contracts\NotificationChannelContract;

class SmsChannel implements NotificationChannelContract
{
    public function send(NotificationMessageData $message): void
    {
        throw new \RuntimeException('SMS channel is not implemented in this POC — wiring point only.');
    }
}
