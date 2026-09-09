<?php

return [
    // 'provider' resolves real gateways by integration type; 'mock' forces the Mock gateway app-wide.
    'gateway' => env('CALENDAR_GATEWAY', 'provider'),

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Minutes before starts_at to send reminders (spec §11).
    'reminder_offsets_minutes' => [1440, 60],
];
