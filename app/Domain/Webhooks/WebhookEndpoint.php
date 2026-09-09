<?php

namespace App\Domain\Webhooks;

use App\Domain\Tenants\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Domain\Webhooks\WebhookEndpointFactory;

class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'url', 'secret', 'events', 'active'];

    protected $casts = [
        'events' => 'array',
        'active' => 'bool',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): WebhookEndpointFactory
    {
        return WebhookEndpointFactory::new();
    }
}
