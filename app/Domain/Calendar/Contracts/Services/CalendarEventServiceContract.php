<?php

namespace App\Domain\Calendar\Contracts\Services;

use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Data\ProviderEventData;
use App\Domain\Calendar\Data\CalendarEventDraftData;

interface CalendarEventServiceContract
{
    public function create(Integration $integration, CalendarEventDraftData $draft): ProviderEventData;

    public function delete(Integration $integration, string $providerEventId): void;

    public function exists(Integration $integration, string $providerEventId): bool;
}
