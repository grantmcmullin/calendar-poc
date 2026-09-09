<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Domain\Tenants\Tenant;
use App\Domain\Integrations\Integration;
use App\Domain\Integrations\Enums\IntegrationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['calendar.gateway' => 'mock']);
        $this->seed(\Database\Seeders\DemoSeeder::class);
    }

    public function test_setup_page_renders_settings_and_disconnected_state(): void
    {
        $this->get('/setup')->assertOk()
            ->assertSee('Connect Google Calendar')
            ->assertSee('Meeting length');
    }

    public function test_settings_update_persists_and_validates_meeting_length(): void
    {
        $payload = [
            'available_days' => ['monday', 'wednesday'],
            'office_starts_at' => '10:00',
            'office_ends_at' => '16:00',
            'meeting_length_minutes' => 45,
            'timezone' => 'America/Chicago',
        ];

        $this->put('/setup/settings', $payload)->assertRedirect('/setup');
        $settings = Tenant::firstOrFail()->bookingSettings->fresh();
        $this->assertSame(['monday', 'wednesday'], $settings->available_days);
        $this->assertSame(45, $settings->meeting_length_minutes);

        $this->put('/setup/settings', array_merge($payload, ['meeting_length_minutes' => 20]))
            ->assertSessionHasErrors('meeting_length_minutes');
    }

    public function test_settings_update_persists_timezone_to_tenant(): void
    {
        $payload = [
            'available_days' => ['monday', 'wednesday'],
            'office_starts_at' => '10:00',
            'office_ends_at' => '16:00',
            'meeting_length_minutes' => 45,
            'timezone' => 'America/Denver',
        ];

        $this->put('/setup/settings', $payload)->assertRedirect('/setup');

        $this->assertSame('America/Denver', Tenant::firstOrFail()->fresh()->timezone);
    }

    public function test_settings_update_rejects_invalid_timezone(): void
    {
        $payload = [
            'available_days' => ['monday', 'wednesday'],
            'office_starts_at' => '10:00',
            'office_ends_at' => '16:00',
            'meeting_length_minutes' => 45,
            'timezone' => 'Not/A_Real_Zone',
        ];

        $this->put('/setup/settings', $payload)->assertSessionHasErrors('timezone');
        $this->assertNotSame('Not/A_Real_Zone', Tenant::firstOrFail()->timezone);
    }

    public function test_google_redirect_stores_state_and_redirects_to_provider(): void
    {
        $response = $this->get('/setup/google/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://mock.test/authorize?state=', $response->headers->get('Location'));
        $this->assertNotNull(session('calendar_oauth_state'));
    }

    public function test_callback_with_valid_state_creates_integration(): void
    {
        session(['calendar_oauth_state' => 'state-1']);

        $this->withSession(['calendar_oauth_state' => 'state-1'])
            ->get('/setup/google/callback?code=abc&state=state-1')
            ->assertRedirect('/setup');

        $integration = Integration::firstOrFail();
        $this->assertSame(IntegrationType::Google->value, $integration->type);
        $this->assertSame('mock-access', $integration->api_token);
        $this->assertSame('mock@example.com', data_get($integration->data, 'account_email'));
        $this->assertSame('primary', data_get($integration->data, 'calendar_id'));
    }

    public function test_callback_with_error_query_param_redirects_without_exchange(): void
    {
        $this->withSession(['calendar_oauth_state' => 'state-1'])
            ->get('/setup/google/callback?error=access_denied&state=state-1')
            ->assertRedirect('/setup');

        $this->assertSame(0, Integration::count());
    }

    public function test_callback_with_bad_state_is_rejected(): void
    {
        $this->withSession(['calendar_oauth_state' => 'state-1'])
            ->get('/setup/google/callback?code=abc&state=wrong')
            ->assertStatus(403);
        $this->assertSame(0, Integration::count());
    }

    public function test_disconnect_revokes_and_deletes_integration(): void
    {
        Integration::factory()->for(Tenant::firstOrFail())->create();

        $this->post('/setup/google/disconnect')->assertRedirect('/setup');
        $this->assertSame(0, Integration::count());
    }
}
