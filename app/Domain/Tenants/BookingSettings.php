<?php

namespace App\Domain\Tenants;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Domain\Tenants\BookingSettingsFactory;

class BookingSettings extends Model
{
    /** @use HasFactory<BookingSettingsFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'available_days', 'office_starts_at', 'office_ends_at', 'meeting_length_minutes'];

    protected $casts = [
        'available_days' => 'array',
        'meeting_length_minutes' => 'integer',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function newFactory(): BookingSettingsFactory
    {
        return BookingSettingsFactory::new();
    }
}
