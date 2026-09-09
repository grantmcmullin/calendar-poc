@extends('layouts.app')

@section('title', 'Setup')

@section('content')
    <div class="space-y-8">
        {{-- Integration card --}}
        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold">Google Calendar</h2>

            @if ($integration === null)
                <p class="mb-4 text-sm text-gray-600">No Google account connected yet.</p>
                <a
                    href="{{ route('setup.google.redirect') }}"
                    class="inline-block rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                >
                    Connect Google Calendar
                </a>
            @else
                @if ($integration->requiresReauth())
                    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                        Google authorization expired &mdash; reconnect required.
                        <a href="{{ route('setup.google.redirect') }}" class="ml-1 font-medium underline">Reconnect</a>
                    </div>
                @endif

                <dl class="mb-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">Account</dt>
                        <dd>{{ data_get($integration->data, 'account_email') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Calendar</dt>
                        <dd>{{ data_get($integration->data, 'calendar_id') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Expires at</dt>
                        <dd>{{ optional($integration->expires_at)->toDateTimeString() }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('setup.google.disconnect') }}">
                    @csrf
                    <button
                        type="submit"
                        class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50"
                    >
                        Disconnect
                    </button>
                </form>
            @endif

            <p class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Google OAuth apps in Testing status expire refresh tokens after 7 days &mdash; expect weekly re-auth in this POC.
            </p>
        </section>

        {{-- Settings form --}}
        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold">Booking settings</h2>

            <form method="POST" action="{{ route('setup.settings.update') }}" class="space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="timezone" class="mb-1 block text-sm font-medium text-gray-700">Tenant timezone</label>
                    @php
                        $curatedTimezones = ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Phoenix'];
                        $currentTimezone = old('timezone', $tenant->timezone ?? '');
                        $timezoneOptions = collect($curatedTimezones)
                            ->when(filled($currentTimezone), fn ($zones) => $zones->push($currentTimezone))
                            ->unique()
                            ->values();
                    @endphp
                    <select
                        id="timezone"
                        name="timezone"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm sm:w-64"
                    >
                        @foreach ($timezoneOptions as $tz)
                            <option value="{{ $tz }}" @selected($currentTimezone === $tz)>{{ $tz }}</option>
                        @endforeach
                    </select>
                    @error('timezone')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <span class="mb-2 block text-sm font-medium text-gray-700">Available days</span>
                    <div class="flex flex-wrap gap-4">
                        @foreach (\App\Domain\Tenants\Enums\Weekday::cases() as $day)
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    name="available_days[]"
                                    value="{{ $day->value }}"
                                    @checked(in_array($day->value, old('available_days', $settings->available_days ?? []), true))
                                >
                                {{ ucfirst($day->value) }}
                            </label>
                        @endforeach
                    </div>
                    @error('available_days')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="office_starts_at" class="mb-1 block text-sm font-medium text-gray-700">Office starts at</label>
                        <input
                            type="time"
                            id="office_starts_at"
                            name="office_starts_at"
                            value="{{ old('office_starts_at', $settings->office_starts_at ? substr((string) $settings->office_starts_at, 0, 5) : '') }}"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        >
                        @error('office_starts_at')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="office_ends_at" class="mb-1 block text-sm font-medium text-gray-700">Office ends at</label>
                        <input
                            type="time"
                            id="office_ends_at"
                            name="office_ends_at"
                            value="{{ old('office_ends_at', $settings->office_ends_at ? substr((string) $settings->office_ends_at, 0, 5) : '') }}"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        >
                        @error('office_ends_at')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="meeting_length_minutes" class="mb-1 block text-sm font-medium text-gray-700">Meeting length</label>
                    <select
                        id="meeting_length_minutes"
                        name="meeting_length_minutes"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm sm:w-48"
                    >
                        @foreach (\App\Domain\Tenants\Enums\MeetingLength::cases() as $length)
                            <option
                                value="{{ $length->value }}"
                                @selected((int) old('meeting_length_minutes', $settings->meeting_length_minutes ?? null) === $length->value)
                            >
                                {{ $length->value }} minutes
                            </option>
                        @endforeach
                    </select>
                    @error('meeting_length_minutes')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="submit"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                >
                    Save settings
                </button>
            </form>
        </section>

        {{-- Webhook card --}}
        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold">Webhook endpoint</h2>

            @if ($webhookEndpoint === null)
                <p class="text-sm text-gray-600">No webhook endpoint configured.</p>
            @else
                <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">URL</dt>
                        <dd class="break-all">{{ $webhookEndpoint->url }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Secret</dt>
                        <dd class="break-all">{{ $webhookEndpoint->secret }}</dd>
                    </div>
                </dl>
            @endif
        </section>
    </div>
@endsection
