<?php

namespace App\Domain\Tenants;

use Illuminate\Database\Eloquent\Model;
use App\Domain\Integrations\Integration;
use App\Domain\Webhooks\WebhookEndpoint;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'timezone'];

    public function bookingSettings(): HasOne
    {
        return $this->hasOne(BookingSettings::class);
    }

    public function integration(): HasOne
    {
        return $this->hasOne(Integration::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Domain\Tenants\TenantFactory::new();
    }
}
