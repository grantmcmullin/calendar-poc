<?php

namespace App\Domain\Bookings\Actions;

use Throwable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use Illuminate\Support\Facades\Cache;
use App\Domain\Bookings\Data\InviteeData;
use App\Domain\Webhooks\Enums\WebhookEvent;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Domain\Notifications\BookingNotifier;
use App\Domain\Bookings\AvailabilityCalculator;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Notifications\ReminderScheduler;
use App\Domain\Webhooks\SendBookingWebhooksAction;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Exceptions\SlotConflictException;
use App\Domain\Calendar\Exceptions\ProviderNotConfiguredException;

class CreateBookingAction
{
    public function __construct(
        protected CalendarGatewayManager $manager,
        protected AvailabilityCalculator $calculator,
        protected ReminderScheduler $reminders,
        protected BookingNotifier $notifier,
        protected SendBookingWebhooksAction $webhooks,
    ) {
    }

    /** @param array<string, mixed> $tracking */
    public function execute(Tenant $tenant, CarbonImmutable $startTime, InviteeData $invitee, array $tracking, ?Booking $rescheduledFrom = null): Booking
    {
        $integration = $tenant->integration
            ?? throw new ProviderNotConfiguredException('No calendar integration is connected for this tenant.');

        $settings = $tenant->bookingSettings
            ?? throw new ProviderNotConfiguredException('Booking settings are not configured for this tenant.');
        $endTime = $startTime->addMinutes($settings->meeting_length_minutes);
        $gateway = $this->manager->for($integration);

        // Spec §9: per-tenant atomic lock; grid + own-bookings + fresh provider probe are
        // the ONLY double-booking protection — Google does not conflict-check on insert.
        $booking = Cache::lock("booking:tenant:{$tenant->id}", 15)->block(10, function () use ($tenant, $settings, $integration, $gateway, $startTime, $endTime, $invitee, $tracking, $rescheduledFrom): Booking {
            $busy = [
                ...$gateway->availability()->busyBlocks($integration, $startTime, $endTime), // ProviderApiFailedException bubbles → 502
                ...Booking::query()->confirmed()
                    ->where('tenant_id', $tenant->id)
                    ->where('starts_at', '<', $endTime)
                    ->where('ends_at', '>', $startTime)
                    ->get()
                    ->map(fn (Booking $existing) => $existing->asBusyBlock()),
            ];

            if ( ! $this->calculator->isBookable($settings, $tenant->timezone, CarbonImmutable::now(), $startTime, $busy)) {
                throw new SlotConflictException('This slot is no longer available.');
            }

            return Booking::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'provider' => $integration->type,
                'lead_first_name' => $invitee->firstName,
                'lead_last_name' => $invitee->lastName,
                'lead_email' => $invitee->email,
                'lead_phone' => $invitee->phone,
                'lead_timezone' => $invitee->timezone,
                'tracking' => $tracking,
                'starts_at' => $startTime,
                'ends_at' => $endTime,
                'status' => BookingStatus::Confirmed->value,
                'manage_token' => Str::random(64),
                'rescheduled_from_booking_id' => $rescheduledFrom?->id,
            ]);
        });

        try {
            $event = $gateway->events()->create($integration, new CalendarEventDraftData(
                summary: 'Consultation: '.$booking->leadFullName(),
                description: "Booked via ULH.\nPhone: ".$booking->lead_phone,
                start: $startTime,
                end: $endTime,
                timezone: $tenant->timezone,
                attendeeEmail: $booking->lead_email,
                attendeeName: $booking->leadFullName(),
                bookingUuid: $booking->uuid,
            ));
        } catch (Throwable $exception) {
            $booking->delete(); // spec §9: provider failure rolls the row back

            throw $exception;
        }

        $booking->update(['provider_event_id' => $event->id, 'provider_event_link' => $event->link]);

        $this->reminders->scheduleFor($booking);
        $this->notifier->sendConfirmation($booking);
        $this->webhooks->execute($booking, WebhookEvent::BookingCreated);

        return $booking;
    }
}
