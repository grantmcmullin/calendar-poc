<?php

namespace App\Domain\Calendar\Services\Google;

use Illuminate\Http\Response;
use App\Domain\Integrations\Integration;
use Illuminate\Http\Client\RequestException;
use App\Domain\Calendar\Data\ProviderEventData;
use App\Domain\Calendar\Data\CalendarEventDraftData;
use App\Domain\Calendar\Contracts\Services\CalendarEventServiceContract;

class CalendarEventGoogleService implements CalendarEventServiceContract
{
    public function __construct(protected GoogleClient $client)
    {
    }

    public function create(Integration $integration, CalendarEventDraftData $draft): ProviderEventData
    {
        try {
            $response = $this->client->request($integration)->post(
                sprintf('calendars/%s/events?sendUpdates=all', $this->calendarId($integration)),
                [
                    'summary' => $draft->summary,
                    'description' => $draft->description,
                    'start' => ['dateTime' => $draft->start->toIso8601String(), 'timeZone' => $draft->timezone],
                    'end' => ['dateTime' => $draft->end->toIso8601String(), 'timeZone' => $draft->timezone],
                    'attendees' => [['email' => $draft->attendeeEmail, 'displayName' => $draft->attendeeName]],
                    'extendedProperties' => ['private' => ['booking_uuid' => $draft->bookingUuid]],
                ],
            );
        } catch (RequestException $exception) {
            $this->client->mapException($exception, 'createEvent');
        }

        return new ProviderEventData((string) $response->json('id'), $response->json('htmlLink'));
    }

    public function delete(Integration $integration, string $providerEventId): void
    {
        try {
            $this->client->request($integration)->delete(
                sprintf('calendars/%s/events/%s?sendUpdates=all', $this->calendarId($integration), $providerEventId),
            );
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [Response::HTTP_NOT_FOUND, Response::HTTP_GONE], true)) {
                return; // already gone — nothing to delete
            }

            $this->client->mapException($exception, 'deleteEvent');
        }
    }

    public function exists(Integration $integration, string $providerEventId): bool
    {
        try {
            $response = $this->client->request($integration)->get(
                sprintf('calendars/%s/events/%s', $this->calendarId($integration), $providerEventId),
            );
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [Response::HTTP_NOT_FOUND, Response::HTTP_GONE], true)) {
                return false;
            }

            $this->client->mapException($exception, 'eventExists');
        }

        return $response->json('status') !== 'cancelled';
    }

    protected function calendarId(Integration $integration): string
    {
        return data_get($integration->data, 'calendar_id', 'primary');
    }
}
