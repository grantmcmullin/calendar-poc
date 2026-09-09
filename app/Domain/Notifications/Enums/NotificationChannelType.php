<?php

namespace App\Domain\Notifications\Enums;

enum NotificationChannelType: string
{
    case Mail = 'mail';
    case Sms = 'sms';
}
