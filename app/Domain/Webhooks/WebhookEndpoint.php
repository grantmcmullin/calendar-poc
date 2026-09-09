<?php

namespace App\Domain\Webhooks;

use App\Domain\Tenants\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WebhookEndpoint extends Model
{
    use HasFactory;

    protected $fillable = ['tenant_id', 'url', 'secret', 'events', 'active'];

    protected $casts = [
        'events' => 'array',
        'active' => 'bool',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Domain\Webhooks\WebhookEndpointFactory::new();
    }
}
