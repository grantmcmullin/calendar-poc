<?php

namespace App\Domain\Calendar\Services\Mock;

use Carbon\CarbonImmutable;
use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Data\BusyBlockData;
use App\Domain\Calendar\Data\OAuthTokensData;
use App\Domain\Calendar\Data\ProviderEventData;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Contracts\Services\CalendarGatewayContract;
use App\Domain\Calendar\Contracts\Services\CalendarAuthServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarEventServiceContract;
use App\Domain\Calendar\Contracts\Services\CalendarAvailabilityServiceContract;

class CalendarMockService implements CalendarGatewayContract, CalendarAuthServiceContract, CalendarAvailabilityServiceContract, CalendarEventServiceContract
{
    /** @var array<int, BusyBlockData> */
    public array $busy = [];

    public bool $failCreate = false;

    public bool $failProbe = false;

    public bool $failExists = false;

    /** @var array<string, CalendarEventDraftData> */
    public array $createdEvents = [];

    /** @var array<int, string> */
    public array $deletedEventIds = [];

    public function auth(): CalendarAuthServiceContract
    {
        return $this;
    }

    public function availability(): CalendarAvailabilityServiceContract
    {
        return $this;
    }

    public function events(): CalendarEventServiceContract
    {
        return $this;
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://mock.test/authorize?state='.$state;
    }

    public function exchangeCode(string $code): OAuthTokensData
    {
        return new OAuthTokensData('mock-access', 'mock-refresh', CarbonImmutable::now()->addHour(), 'mock@example.com');
    }

    public function refreshTokens(Integration $integration): void
    {
        $integration->update(['api_token' => 'mock-access-refreshed', 'expires_at' => now()->addHour()]);
    }

    public function revoke(Integration $integration): void
    {
    }

    public function busyBlocks(Integration $integration, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($this->failProbe) {
            throw new ProviderApiFailedException('Mock probe failure.');
        }

        return array_values(array_filter(
            $this->busy,
            fn (BusyBlockData $block): bool => $block->start->lessThan($to) && $from->lessThan($block->end),
        ));
    }

    public function create(Integration $integration, CalendarEventDraftData $draft): ProviderEventData
    {
        if ($this->failCreate) {
            throw new ProviderApiFailedException('Mock create failure.');
        }

        $id = 'mock-event-'.$draft->bookingUuid;
        $this->createdEvents[$id] = $draft;

        return new ProviderEventData($id, 'https://mock.test/events/'.$id);
    }

    public function delete(Integration $integration, string $providerEventId): void
    {
        $this->deletedEventIds[] = $providerEventId;
    }

    public function exists(Integration $integration, string $providerEventId): bool
    {
        if ($this->failExists) {
            throw new ProviderApiFailedException('Mock exists failure.');
        }

        return array_key_exists($providerEventId, $this->createdEvents)
            && ! in_array($providerEventId, $this->deletedEventIds, true);
    }
}
