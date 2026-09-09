<?php

namespace App\Domain\Calendar\Data;

final class ProviderEventData
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $link = null,
    ) {
    }
}
