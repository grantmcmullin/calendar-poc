<?php

namespace App\Domain\Calendar\Services\Google;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use App\Domain\Integrations\Integration;
use Illuminate\Http\Client\RequestException;
use App\Domain\Calendar\Data\OAuthTokensData;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Exceptions\ProviderAuthExpiredException;
use App\Domain\Calendar\Contracts\Services\CalendarAuthServiceContract;

class CalendarAuthGoogleService implements CalendarAuthServiceContract
{
    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    protected const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    protected const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    protected const SCOPES = [
        'openid',
        'email',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.freebusy',
    ];

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('calendar.google.client_id'),
            'redirect_uri' => config('calendar.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): OAuthTokensData
    {
        try {
            $response = Http::asForm()->throw()->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => config('calendar.google.client_id'),
                'client_secret' => config('calendar.google.client_secret'),
                'redirect_uri' => config('calendar.google.redirect_uri'),
            ]);

            $email = Http::withToken($response->json('access_token'))
                ->throw()->get(self::USERINFO_URL)->json('email');
        } catch (RequestException $exception) {
            throw new ProviderApiFailedException('Google token exchange failed: '.$exception->getMessage(), previous: $exception);
        }

        return new OAuthTokensData(
            accessToken: $response->json('access_token'),
            refreshToken: $response->json('refresh_token'),
            expiresAt: CarbonImmutable::now()->addSeconds((int) $response->json('expires_in')),
            accountEmail: (string) $email,
        );
    }

    public function refreshTokens(Integration $integration): void
    {
        if (blank($integration->refresh_token)) {
            $integration->flagReauthRequired();

            throw new ProviderAuthExpiredException('No refresh token stored; tenant must reconnect Google.');
        }

        try {
            $response = Http::asForm()->throw()->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
                'client_id' => config('calendar.google.client_id'),
                'client_secret' => config('calendar.google.client_secret'),
            ]);
        } catch (RequestException $exception) {
            $integration->flagReauthRequired();

            throw new ProviderAuthExpiredException('Google token refresh failed; tenant must reconnect.', previous: $exception);
        }

        $integration->update([
            'api_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token') ?? $integration->refresh_token,
            'expires_at' => now()->addSeconds((int) $response->json('expires_in')),
        ]);
        $integration->clearReauthFlag();
    }

    public function revoke(Integration $integration): void
    {
        Http::asForm()->post(self::REVOKE_URL, ['token' => $integration->api_token]);
        // Best-effort: a failed revoke must not block disconnecting locally (spec §7).
    }
}
