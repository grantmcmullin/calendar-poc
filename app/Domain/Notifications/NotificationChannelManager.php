<?php

namespace App\Domain\Notifications;

use App\Domain\Notifications\Channels\SmsChannel;
use App\Domain\Notifications\Channels\MailChannel;
use App\Domain\Notifications\Enums\NotificationChannelType;
use App\Domain\Notifications\Contracts\NotificationChannelContract;

class NotificationChannelManager
{
    public function channel(NotificationChannelType $type): NotificationChannelContract
    {
        return match ($type) {
            NotificationChannelType::Mail => app(MailChannel::class),
            NotificationChannelType::Sms => app(SmsChannel::class),
        };
    }
}
