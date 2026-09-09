<?php

namespace App\Domain\Tenants;

use Illuminate\Database\Eloquent\Model;
use App\Domain\Integrations\Integration;
use App\Domain\Webhooks\WebhookEndpoint;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\Domain\Tenants\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'timezone'];

    /** @return HasOne<BookingSettings, $this> */
    public function bookingSettings(): HasOne
    {
        return $this->hasOne(BookingSettings::class);
    }

    /** @return HasOne<Integration, $this> */
    public function integration(): HasOne
    {
        return $this->hasOne(Integration::class);
    }

    /** @return HasMany<WebhookEndpoint, $this> */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
