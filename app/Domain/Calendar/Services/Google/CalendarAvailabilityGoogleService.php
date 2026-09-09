<?php

namespace App\Domain\Calendar\Services\Google;

use Carbon\CarbonImmutable;
use App\Domain\Integrations\Integration;
use App\Domain\Calendar\Data\BusyBlockData;
use Illuminate\Http\Client\RequestException;
use App\Domain\Calendar\Exceptions\ProviderApiFailedException;
use App\Domain\Calendar\Contracts\Services\CalendarAvailabilityServiceContract;

class CalendarAvailabilityGoogleService implements CalendarAvailabilityServiceContract
{
    public function __construct(protected GoogleClient $client)
    {
    }

    public function busyBlocks(Integration $integration, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calendarId = data_get($integration->data, 'calendar_id', 'primary');

        try {
            $response = $this->client->request($integration)->post('freeBusy', [
                'timeMin' => $from->toIso8601String(),
                'timeMax' => $to->toIso8601String(),
                'items' => [['id' => $calendarId]],
            ]);
        } catch (RequestException $exception) {
            $this->client->mapException($exception, 'busyBlocks');
        }

        $calendar = $response->json('calendars')[$calendarId] ?? [];

        if (filled($calendar['errors'] ?? [])) {
            throw new ProviderApiFailedException('Google freeBusy returned calendar errors: '.json_encode($calendar['errors']));
        }

        return array_map(
            fn (array $block): BusyBlockData => new BusyBlockData(
                CarbonImmutable::parse($block['start'])->utc(),
                CarbonImmutable::parse($block['end'])->utc(),
            ),
            $calendar['busy'] ?? [],
        );
    }
}
