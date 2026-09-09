<?php

namespace App\Domain\Calendar\Services\Google;

use Throwable;
use Illuminate\Support\Facades\Http;
use App\Domain\Integrations\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Exceptions\ProviderAuthExpiredException;

class GoogleClient
{
    // Trailing slash matters: Guzzle base_uri drops the /calendar/v3 path segment when a
    // request path starts with "/" — so BASE_URL ends with "/" and all paths are relative.
    public const BASE_URL = 'https://www.googleapis.com/calendar/v3/';

    public function __construct(protected CalendarAuthGoogleService $auth)
    {
    }

    public function request(Integration $integration): PendingRequest
    {
        return Http::withToken($this->freshAccessToken($integration))
            ->baseUrl(self::BASE_URL)
            ->throw()
            ->retry(2, 100, function (Throwable $exception, PendingRequest $request) use ($integration): bool {
                if ($exception instanceof RequestException && $exception->response->status() === 401) {
                    $this->auth->refreshTokens($integration); // throws ProviderAuthExpiredException on failure
                    $request->withToken((string) $integration->api_token);

                    return true;
                }

                return false;
            }, throw: true);
    }

    protected function freshAccessToken(Integration $integration): string
    {
        if ($integration->tokenIsExpired()) {
            $this->auth->refreshTokens($integration);
        }

        return (string) $integration->api_token;
    }

    public function mapException(RequestException $exception, string $method): never
    {
        logger()->error('[Calendar] [Google] API request failed', [
            'method' => $method,
            'status' => $exception->response->status(),
            'body' => $exception->response->body(),
        ]);

        throw new ProviderApiFailedException("Google {$method} failed: ".$exception->getMessage(), previous: $exception);
    }
}
