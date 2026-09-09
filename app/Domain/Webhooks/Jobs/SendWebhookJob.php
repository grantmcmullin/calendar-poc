<?php

namespace App\Domain\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use App\Domain\Webhooks\WebhookDelivery;
use Illuminate\Queue\InteractsWithQueue;
use App\Domain\Webhooks\WebhookSignature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public WebhookDelivery $delivery)
    {
    }

    public function handle(): void
    {
        $body = json_encode($this->delivery->payload);
        $signature = WebhookSignature::header($this->delivery->endpoint->secret, $body, time());

        $this->delivery->update(['attempts' => $this->delivery->attempts + 1, 'signature' => $signature]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Calendar-Service-Signature' => $signature,
        ])->withBody($body, 'application/json')->post($this->delivery->endpoint->url);

        $this->delivery->update([
            'response_status' => $response->status(),
            'delivered_at' => $response->successful() ? now() : null,
        ]);

        if ( ! $response->successful()) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
