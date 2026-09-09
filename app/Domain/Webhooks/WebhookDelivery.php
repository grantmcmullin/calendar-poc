<?php

namespace App\Domain\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Domain\Webhooks\WebhookDeliveryFactory;

class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'webhook_endpoint_id', 'event', 'payload', 'signature',
        'response_status', 'attempts', 'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'delivered_at' => 'datetime',
    ];

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    protected static function newFactory(): WebhookDeliveryFactory
    {
        return WebhookDeliveryFactory::new();
    }
}
