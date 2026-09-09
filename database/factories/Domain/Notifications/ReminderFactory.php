<?php

namespace Database\Factories\Domain\Notifications;

use App\Domain\Bookings\Booking;
use App\Domain\Notifications\Reminder;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReminderFactory extends Factory
{
    protected $model = Reminder::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'recipient' => 'lead',
            'channel' => 'mail',
            'send_at' => now()->addHour(),
        ];
    }
}
