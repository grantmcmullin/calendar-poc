<?php

namespace App\Domain\Notifications\Jobs;

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

                if ($claimed === 1) {
                    app(BookingNotifier::class)->sendReminder($reminder);
                }
            });
    }
}
