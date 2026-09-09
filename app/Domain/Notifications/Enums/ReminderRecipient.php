<?php

namespace App\Domain\Notifications\Enums;

enum ReminderRecipient: string
{
    case Lead = 'lead';
    case Tenant = 'tenant';
}
