<?php

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\Data\NotificationMessageData;

interface NotificationChannelContract
{
    public function send(NotificationMessageData $message): void;
}
