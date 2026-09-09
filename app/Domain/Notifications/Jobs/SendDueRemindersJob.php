<?php

namespace App\Domain\Notifications\Jobs;

use Throwable;
use Illuminate\Bus\Queueable;
use App\Domain\Notifications\Reminder;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Domain\Notifications\BookingNotifier;

class SendDueRemindersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        Reminder::query()
            ->whereNull('sent_at')
            ->where('send_at', '<=', now())
            ->orderBy('id')
            ->get()
            ->each(function (Reminder $reminder): void {
                $claimed = Reminder::whereKey($reminder->id)->whereNull('sent_at')->update(['sent_at' => now()]);

                if ($claimed !== 1) {
                    return;
                }

                try {
                    app(BookingNotifier::class)->sendReminder($reminder);
                } catch (Throwable $exception) {
                    // One failing reminder must not abort the batch. Revert sent_at so the
                    // next minute's run retries this reminder (see task-14 review).
                    logger()->error('[Notifications] Failed to send reminder', [
                        'reminder_id' => $reminder->id,
                        'message' => $exception->getMessage(),
                    ]);
                    Reminder::whereKey($reminder->id)->update(['sent_at' => null]);
                }
            });
    }
}
