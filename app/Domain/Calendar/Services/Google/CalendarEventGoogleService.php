<?php

namespace App\Domain\Calendar\Services\Google;

use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Data\ProviderEventData;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Contracts\Services\CalendarEventServiceContract;

class CalendarEventGoogleService implements CalendarEventServiceContract
{
    public function create(Integration $integration, CalendarEventDraftData $draft): ProviderEventData
    {
        throw new ProviderApiFailedException('pending Task 6');
    }

    public function delete(Integration $integration, string $providerEventId): void
    {
        throw new ProviderApiFailedException('pending Task 6');
    }

    public function exists(Integration $integration, string $providerEventId): bool
    {
        throw new ProviderApiFailedException('pending Task 6');
    }
}
