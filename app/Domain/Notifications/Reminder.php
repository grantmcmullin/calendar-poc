<?php

namespace App\Domain\Notifications;

use App\Domain\Bookings\Booking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Domain\Notifications\ReminderFactory;

class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    protected $fillable = ['booking_id', 'recipient', 'channel', 'send_at', 'sent_at'];

    protected $casts = [
        'send_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    protected static function newFactory(): ReminderFactory
    {
        return ReminderFactory::new();
    }
}
