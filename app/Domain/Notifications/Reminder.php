<?php

namespace App\Domain\Notifications;

use App\Domain\Bookings\Booking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Reminder extends Model
{
    use HasFactory;

    protected $fillable = ['booking_id', 'recipient', 'channel', 'send_at', 'sent_at'];

    protected $casts = [
        'send_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Domain\Notifications\ReminderFactory::new();
    }
}
