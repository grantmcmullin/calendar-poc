<?php

namespace Tests\Feature\Domain\Calendar\Google;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use App\Domain\Integrations\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Calendar\Exceptions\ProviderAuthExpiredException;
use App\Domain\Calendar\Services\Google\CalendarAuthGoogleService;

class CalendarAuthGoogleServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.google' => [
            'client_id' => 'test-client', 'client_secret' => 'test-secret',
            'redirect_uri' => 'https://calendar-service.test/setup/google/callback',
        ]]);
    }

    public function test_authorization_url_contains_required_parameters(): void
    {
        $url = app(CalendarAuthGoogleService::class)->authorizationUrl('state-123');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame(
            'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy',
            $query['scope'],
        );
    }

    public function test_exchange_code_returns_tokens_and_account_email(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3599, 'token_type' => 'Bearer',
            ]),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'attorney@gmail.com']),
        ]);

        $tokens = app(CalendarAuthGoogleService::class)->exchangeCode('auth-code');

        $this->assertSame('at-1', $tokens->accessToken);
        $this->assertSame('rt-1', $tokens->refreshToken);
        $this->assertSame('attorney@gmail.com', $tokens->accountEmail);
        $this->assertTrue($tokens->expiresAt->isFuture());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
            && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code');
    }

    public function test_refresh_persists_new_access_token_and_keeps_refresh_token(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-2', 'expires_in' => 3599])]);
        $integration = Integration::factory()->create(['api_token' => 'old', 'refresh_token' => 'rt-1']);

        app(CalendarAuthGoogleService::class)->refreshTokens($integration);

        $integration->refresh();
        $this->assertSame('at-2', $integration->api_token);
        $this->assertSame('rt-1', $integration->refresh_token); // Google may omit it; keep existing
        $this->assertTrue($integration->expires_at->isFuture());
    }

    public function test_refresh_failure_flags_reauth_and_throws(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $integration = Integration::factory()->create(['refresh_token' => 'rt-dead']);

        try {
            app(CalendarAuthGoogleService::class)->refreshTokens($integration);
            $this->fail('Expected ProviderAuthExpiredException');
        } catch (ProviderAuthExpiredException) {
            $this->assertTrue($integration->fresh()->requiresReauth());
        }
    }

    public function test_refresh_without_refresh_token_throws(): void
    {
        $integration = Integration::factory()->create(['refresh_token' => null]);
        $this->expectException(ProviderAuthExpiredException::class);
        app(CalendarAuthGoogleService::class)->refreshTokens($integration);
    }

    public function test_revoke_swallows_connection_failures(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused.')]);
        $integration = Integration::factory()->create();

        app(CalendarAuthGoogleService::class)->revoke($integration);

        $this->assertTrue(true); // no exception bubbled — disconnect must not be blocked
    }
}
