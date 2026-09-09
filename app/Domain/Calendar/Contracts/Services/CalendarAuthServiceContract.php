<?php

namespace App\Domain\Calendar\Contracts\Services;

use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Data\OAuthTokensData;

interface CalendarAuthServiceContract
{
    public function authorizationUrl(string $state): string;

    public function exchangeCode(string $code): OAuthTokensData;

    public function refreshTokens(Integration $integration): void;

    public function revoke(Integration $integration): void;
}
