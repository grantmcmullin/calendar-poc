<?php

namespace App\Http\Controllers\Setup;

use App\Domain\Tenants\Tenant;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;

class SetupController extends Controller
{
    public function show(): View
    {
        $tenant = Tenant::firstOrFail();

        return view('setup', [
            'tenant' => $tenant,
            'settings' => $tenant->bookingSettings,
            'integration' => $tenant->integration,
            'webhookEndpoint' => $tenant->webhookEndpoints()->first(),
        ]);
    }
}
