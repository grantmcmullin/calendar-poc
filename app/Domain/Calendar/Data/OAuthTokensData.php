<?php

namespace App\Domain\Calendar\Data;

use Carbon\CarbonImmutable;

final class OAuthTokensData
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly CarbonImmutable $expiresAt,
        public readonly string $accountEmail,
    ) {
    }
}
