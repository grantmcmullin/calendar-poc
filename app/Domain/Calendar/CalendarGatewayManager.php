<?php

namespace App\Domain\Calendar;

use App\Domain\Integrations\Integration;
use App\Domain\Integrations\Enums\IntegrationType;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Calendar\Services\Google\CalendarGoogleService;
use App\Domain\Calendar\Exceptions\ProviderNotConfiguredException;
use App\Domain\Calendar\Contracts\Services\CalendarGatewayContract;

class CalendarGatewayManager
{
    public function for(Integration $integration): CalendarGatewayContract
    {
        return $this->forType(IntegrationType::from($integration->type));
    }

    public function forType(IntegrationType $type): CalendarGatewayContract
    {
        if (config('calendar.gateway') === 'mock') {
            return app(CalendarMockService::class);
        }

        return match ($type) {
            IntegrationType::Google => app(CalendarGoogleService::class),
            IntegrationType::Microsoft => throw new ProviderNotConfiguredException('Microsoft provider is not implemented in this POC.'),
        };
    }
}
