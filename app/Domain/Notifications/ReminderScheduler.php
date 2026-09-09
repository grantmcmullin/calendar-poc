<?php

namespace App\Domain\Notifications;

use App\Domain\Bookings\Booking;
use App\Domain\Notifications\Enums\ReminderRecipient;
use App\Domain\Notifications\Enums\NotificationChannelType;

class ReminderScheduler
{
    public function scheduleFor(Booking $booking): void
    {
        foreach (config('calendar.reminder_offsets_minutes') as $offset) {
            $sendAt = $booking->starts_at->subMinutes($offset);

            if ($sendAt->isPast()) {
                continue;
            }

            foreach (ReminderRecipient::cases() as $recipient) {
                Reminder::create([
                    'booking_id' => $booking->id,
                    'recipient' => $recipient->value,
                    'channel' => NotificationChannelType::Mail->value,
                    'send_at' => $sendAt,
                ]);
            }
        }
    }

    public function cancelFor(Booking $booking): void
    {
        Reminder::where('booking_id', $booking->id)->whereNull('sent_at')->delete();
    }
}
