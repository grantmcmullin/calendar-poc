<?php

namespace App\Http\Controllers\Setup;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Domain\Tenants\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use App\Domain\Integrations\Integration;
use App\Domain\Calendar\CalendarGatewayManager;
use App\Domain\Integrations\Enums\IntegrationType;

class GoogleOAuthController extends Controller
{
    public function redirect(CalendarGatewayManager $manager): RedirectResponse
    {
        $state = Str::random(40);
        session(['calendar_oauth_state' => $state]);

        return redirect()->away($manager->forType(IntegrationType::Google)->auth()->authorizationUrl($state));
    }

    public function callback(Request $request, CalendarGatewayManager $manager): RedirectResponse
    {
        abort_unless(
            filled($request->query('state')) && $request->query('state') === session()->pull('calendar_oauth_state'),
            403,
            'OAuth state mismatch.',
        );

        $tokens = $manager->forType(IntegrationType::Google)->auth()->exchangeCode((string) $request->query('code'));

        Integration::updateOrCreate(
            ['tenant_id' => Tenant::firstOrFail()->id, 'type' => IntegrationType::Google->value],
            [
                'api_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'expires_at' => $tokens->expiresAt,
                'data' => ['account_email' => $tokens->accountEmail, 'calendar_id' => 'primary'],
            ],
        );

        return redirect()->route('setup.show')->with('status', 'Google Calendar connected.');
    }

    public function disconnect(CalendarGatewayManager $manager): RedirectResponse
    {
        $integration = Tenant::firstOrFail()->integration;

        if ($integration !== null) {
            $manager->for($integration)->auth()->revoke($integration);
            $integration->delete();
        }

        return redirect()->route('setup.show')->with('status', 'Disconnected.');
    }
}
