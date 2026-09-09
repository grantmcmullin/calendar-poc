<?php

namespace App\Domain\Notifications\Channels;

use Illuminate\Support\Facades\Mail;
use App\Domain\Notifications\Data\NotificationMessageData;
use App\Domain\Notifications\Mail\BookingNotificationMail;
use App\Domain\Notifications\Contracts\NotificationChannelContract;

class MailChannel implements NotificationChannelContract
{
    public function send(NotificationMessageData $message): void
    {
        Mail::to($message->recipientEmail)->send(new BookingNotificationMail($message));
    }
}
