<?php

namespace Tests\Feature\Domain\Notifications;

use Tests\TestCase;
use App\Domain\Bookings\Booking;
use Illuminate\Support\Facades\Mail;
use App\Domain\Notifications\Reminder;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Notifications\ReminderScheduler;
use App\Domain\Bookings\Enums\CancellationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Notifications\Jobs\SendDueRemindersJob;
use App\Domain\Notifications\Mail\BookingNotificationMail;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_creates_lead_and_tenant_rows_per_offset_skipping_past(): void
    {
        $this->travelTo('2026-09-14T00:00:00Z');
        // Starts in 2 hours: the T-24h offset is in the past and must be skipped.
        $booking = Booking::factory()->create(['starts_at' => now()->addHours(2), 'ends_at' => now()->addHours(2)->addMinutes(30)]);

        app(ReminderScheduler::class)->scheduleFor($booking);

        $this->assertSame(2, Reminder::count()); // T-1h for lead + tenant only
        $this->assertSame(['lead', 'tenant'], Reminder::orderBy('recipient')->pluck('recipient')->all());
        $this->assertTrue(Reminder::first()->send_at->equalTo(now()->addHour()));
    }

    public function test_due_reminders_job_sends_and_stamps_only_due_rows(): void
    {
        Mail::fake();
        $booking = Booking::factory()->create();
        $due = Reminder::factory()->for($booking)->create(['send_at' => now()->subMinute()]);
        $future = Reminder::factory()->for($booking)->create(['send_at' => now()->addHour()]);
        $sent = Reminder::factory()->for($booking)->create(['send_at' => now()->subHour(), 'sent_at' => now()->subHour()]);

        (new SendDueRemindersJob())->handle();

        Mail::assertSent(BookingNotificationMail::class, 1);
        $this->assertNotNull($due->fresh()->sent_at);
        $this->assertNull($future->fresh()->sent_at);
    }

    public function test_confirmation_mails_both_parties_and_cancellation_mails_non_initiator(): void
    {
        Mail::fake();
        $booking = Booking::factory()->create();

        app(BookingNotifier::class)->sendConfirmation($booking);
        Mail::assertSent(BookingNotificationMail::class, 2); // lead + tenant

        app(BookingNotifier::class)->sendCancellation($booking, CancellationSource::Lead);
        Mail::assertSent(BookingNotificationMail::class, 3); // +1: tenant only

        app(BookingNotifier::class)->sendCancellation($booking, CancellationSource::Provider);
        Mail::assertSent(BookingNotificationMail::class, 4); // +1: lead only
    }

    public function test_sms_channel_is_a_visible_stub(): void
    {
        $this->expectException(\RuntimeException::class);
        app(\App\Domain\Notifications\Channels\SmsChannel::class)->send(
            new \App\Domain\Notifications\Data\NotificationMessageData('Jane', 'jane@example.com', null, 'x', []),
        );
    }

    public function test_booking_notification_mail_renders_lines(): void
    {
        $mail = new BookingNotificationMail(new \App\Domain\Notifications\Data\NotificationMessageData('Jane Doe', 'jane@example.com', null, 'Test subject', ['Line one', 'Line two']));

        $rendered = $mail->render();

        $this->assertStringContainsString('Line one', $rendered);
        $this->assertStringContainsString('Line two', $rendered);
    }
}
