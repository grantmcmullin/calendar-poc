<?php

namespace App\Domain\Bookings;

use Carbon\CarbonImmutable;
use App\Domain\Tenants\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Bookings\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'tenant_id', 'provider', 'provider_event_id', 'provider_event_link',
        'lead_first_name', 'lead_last_name', 'lead_email', 'lead_phone', 'lead_timezone',
        'tracking', 'starts_at', 'ends_at', 'status', 'canceled_at', 'cancellation_source',
        'manage_token', 'rescheduled_from_booking_id',
    ];

    protected $casts = [
        'tracking' => 'array',
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'canceled_at' => 'immutable_datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', BookingStatus::Confirmed->value);
    }

    public function asBusyBlock(): BusyBlockData
    {
        return new BusyBlockData(CarbonImmutable::parse($this->starts_at), CarbonImmutable::parse($this->ends_at));
    }

    public function leadFullName(): string
    {
        return trim($this->lead_first_name.' '.$this->lead_last_name);
    }

    protected static function newFactory()
    {
        return \Database\Factories\Domain\Bookings\BookingFactory::new();
    }
}
