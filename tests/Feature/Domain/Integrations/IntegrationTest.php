<?php

namespace Tests\Feature\Domain\Integrations;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\Domain\Integrations\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_at_rest_and_decrypted_on_access(): void
    {
        $integration = Integration::factory()->create(['api_token' => 'plain-token']);

        $raw = DB::table('integrations')->where('id', $integration->id)->value('api_token');

        $this->assertNotSame('plain-token', $raw);
        $this->assertSame('plain-token', $integration->fresh()->api_token);
    }

    public function test_token_expiry_and_reauth_flag(): void
    {
        $integration = Integration::factory()->create(['expires_at' => now()->subMinute()]);
        $this->assertTrue($integration->tokenIsExpired());
        $this->assertFalse($integration->requiresReauth());

        $integration->flagReauthRequired();
        $this->assertTrue($integration->fresh()->requiresReauth());

        $integration->fresh()->clearReauthFlag();
        $this->assertFalse($integration->fresh()->requiresReauth());
    }
}
