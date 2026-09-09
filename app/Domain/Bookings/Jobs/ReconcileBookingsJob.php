<?php

namespace App\Domain\Bookings\Jobs;

use App\Domain\Bookings\Booking;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Bookings\Enums\CancellationSource;
use App\Domain\Bookings\Actions\CancelBookingAction;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Exceptions\ProviderAuthExpiredException;

class ReconcileBookingsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        Booking::query()->confirmed()
            ->whereNotNull('provider_event_id')
            ->where('starts_at', '>', now())
            ->with('tenant.integration')
            ->chunkById(100, function ($bookings): void {
                foreach ($bookings as $booking) {
                    $this->reconcile($booking);
                }
            });
    }

    protected function reconcile(Booking $booking): void
    {
        $integration = $booking->tenant->integration;

        if ($integration === null) {
            return;
        }

        try {
            $exists = app(CalendarGatewayManager::class)->for($integration)
                ->events()->exists($integration, $booking->provider_event_id);
        } catch (ProviderAuthExpiredException) {
            return; // integration already flagged for re-auth by the auth service; skip
        } catch (ProviderApiFailedException $exception) {
            logger()->warning('[Reconcile] Provider error while probing booking; skipping', [
                'booking_id' => $booking->id, 'message' => $exception->getMessage(),
            ]);

            return; // an API blip must never mass-cancel (spec §12)
        }

        if ( ! $exists) {
            try {
                app(CancelBookingAction::class)->execute($booking, CancellationSource::Provider);
            } catch (\Throwable $exception) {
                logger()->error('[Reconcile] Failed to cancel booking with gone provider event; continuing', [
                    'booking_id' => $booking->id, 'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
