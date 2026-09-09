<?php

namespace App\Http\Controllers\Setup;

use App\Domain\Tenants\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\UpdateBookingSettingsRequest;

class BookingSettingsController extends Controller
{
    public function update(UpdateBookingSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Tenant::firstOrFail()->bookingSettings->update([
            ...$validated,
            'office_starts_at' => $validated['office_starts_at'].':00',
            'office_ends_at' => $validated['office_ends_at'].':00',
        ]);

        return redirect()->route('setup.show')->with('status', 'Settings saved.');
    }
}
