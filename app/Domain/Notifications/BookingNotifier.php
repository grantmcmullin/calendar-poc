<?php

namespace App\Domain\Notifications;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Notifications\Enums\ReminderRecipient;
use App\Domain\Notifications\Data\NotificationMessageData;
use App\Domain\Notifications\Enums\NotificationChannelType;

class BookingNotifier
{
    public function __construct(protected NotificationChannelManager $channels)
    {
    }

    public function sendConfirmation(Booking $booking): void
    {
        $this->toLead($booking, 'Your appointment is confirmed', [
            sprintf('Your appointment with %s is confirmed for %s.', $booking->tenant->name, $this->leadLocalTime($booking)),
            'Manage (cancel or reschedule): '.route('manage.show', $booking->manage_token),
        ]);
        $this->toTenant($booking, 'New appointment booked', [
            sprintf('%s booked %s.', $booking->leadFullName(), $this->tenantLocalTime($booking)),
            'Lead: '.$booking->lead_email.' / '.$booking->lead_phone,
        ]);
    }

    public function sendCancellation(Booking $booking, CancellationSource $source): void
    {
        if ($source === CancellationSource::Lead) {
            $this->toTenant($booking, 'Appointment canceled', [
                sprintf('%s canceled the appointment on %s.', $booking->leadFullName(), $this->tenantLocalTime($booking)),
            ]);

            return;
        }

        $this->toLead($booking, 'Your appointment was canceled', [
            sprintf('Your appointment with %s on %s was canceled.', $booking->tenant->name, $this->leadLocalTime($booking)),
        ]);
    }

    public function sendReminder(Reminder $reminder): void
    {
        $booking = $reminder->booking;

        $reminder->recipient === ReminderRecipient::Lead->value
            ? $this->toLead($booking, 'Appointment reminder', [sprintf('Reminder: your appointment with %s is at %s.', $booking->tenant->name, $this->leadLocalTime($booking))])
            : $this->toTenant($booking, 'Appointment reminder', [sprintf('Reminder: %s at %s.', $booking->leadFullName(), $this->tenantLocalTime($booking))]);
    }

    /** @param array<int, string> $lines */
    protected function toLead(Booking $booking, string $subject, array $lines): void
    {
        $this->channels->channel(NotificationChannelType::Mail)->send(new NotificationMessageData(
            $booking->leadFullName(),
            $booking->lead_email,
            $booking->lead_phone,
            $subject,
            $lines,
        ));
    }

    /** @param array<int, string> $lines */
    protected function toTenant(Booking $booking, string $subject, array $lines): void
    {
        $this->channels->channel(NotificationChannelType::Mail)->send(new NotificationMessageData(
            $booking->tenant->name,
            $booking->tenant->email,
            null,
            $subject,
            $lines,
        ));
    }

    protected function leadLocalTime(Booking $booking): string
    {
        return $booking->starts_at->setTimezone($booking->lead_timezone)->format('D, M j Y g:ia T');
    }

    protected function tenantLocalTime(Booking $booking): string
    {
        return $booking->starts_at->setTimezone($booking->tenant->timezone)->format('D, M j Y g:ia T');
    }
}
