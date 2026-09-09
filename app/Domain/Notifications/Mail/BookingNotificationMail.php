<?php

namespace App\Domain\Notifications\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use App\Domain\Notifications\Data\NotificationMessageData;

class BookingNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    // Note: exposed as $notification (not a public $message property) because Illuminate\Mail\Mailer::send()
    // unconditionally overwrites a 'message' view-data key with its own Message wrapper, which would silently
    // break the blade view if this data were public under that name.
    public NotificationMessageData $notification;

    public function __construct(NotificationMessageData $message)
    {
        $this->notification = $message;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notification->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.booking-notification');
    }
}
