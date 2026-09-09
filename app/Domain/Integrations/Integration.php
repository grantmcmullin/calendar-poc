<?php

namespace App\Domain\Integrations;

use App\Domain\Tenants\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Database\Factories\Domain\Integrations\IntegrationFactory;

class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'type', 'api_token', 'refresh_token',
        'expires_at', 'refresh_token_expires_at', 'data',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'refresh_token_expires_at' => 'datetime',
        'data' => 'array',
    ];

    protected $hidden = ['api_token', 'refresh_token'];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tokenIsExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThan(now());
    }

    public function requiresReauth(): bool
    {
        return filled(data_get($this->data, 'requires_reauth_at'));
    }

    public function flagReauthRequired(): void
    {
        $data = $this->data ?? [];
        $data['requires_reauth_at'] = now()->toIso8601String();
        $this->update(['data' => $data]);
    }

    public function clearReauthFlag(): void
    {
        $data = $this->data ?? [];
        unset($data['requires_reauth_at']);
        $this->update(['data' => $data]);
    }

    protected static function newFactory(): IntegrationFactory
    {
        return IntegrationFactory::new();
    }
}
