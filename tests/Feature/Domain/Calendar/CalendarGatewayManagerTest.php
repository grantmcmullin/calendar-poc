<?php

namespace Tests\Feature\Domain\Calendar;

use Tests\TestCase;
use App\Domain\Integrations\Integration;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Integrations\Enums\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domain\Calendar\Services\Mock\CalendarMockService;
use App\Domain\Calendar\Exceptions\ProviderNotConfiguredException;

class CalendarGatewayManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_gateway_is_resolved_when_config_forces_mock(): void
    {
        config(['calendar.gateway' => 'mock']);
        $integration = Integration::factory()->create();

        $gateway = app(CalendarGatewayManager::class)->for($integration);

        $this->assertInstanceOf(CalendarMockService::class, $gateway);
        $this->assertSame($gateway, app(CalendarGatewayManager::class)->for($integration)); // singleton
    }

    public function test_microsoft_type_throws_not_configured(): void
    {
        config(['calendar.gateway' => 'provider']);
        $this->expectException(ProviderNotConfiguredException::class);
        app(CalendarGatewayManager::class)->forType(IntegrationType::Microsoft);
    }
}
