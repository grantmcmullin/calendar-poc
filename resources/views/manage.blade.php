@extends('layouts.app')

@section('title', 'Manage booking')

@section('content')
    @php
        $isCanceled = $booking->status === \App\Domain\Bookings\Enums\BookingStatus::Canceled->value;
        $inviteeData = [
            'first_name' => $booking->lead_first_name,
            'last_name' => $booking->lead_last_name,
            'email' => $booking->lead_email,
            'phone' => $booking->lead_phone,
        ];
    @endphp

    <section class="rounded-lg border border-gray-200 bg-white p-6">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-lg font-semibold">{{ $booking->tenant->name }}</h2>
            <span class="{{ $isCanceled ? 'inline-block rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800' : 'inline-block rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800' }}">
                {{ $booking->status }}
            </span>
        </div>

        <dl class="space-y-2 text-sm text-gray-700">
            <div>
                <dt class="text-gray-500">Lead</dt>
                <dd>{{ $booking->leadFullName() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Start time</dt>
                <dd>{{ $booking->starts_at->setTimezone($booking->lead_timezone)->format('D, M j Y g:ia T') }}</dd>
            </div>
        </dl>

        @if ($isCanceled)
            <p class="mt-6 text-sm font-medium text-red-800">This appointment was canceled.</p>
        @else
            <div class="mt-6">
                <button type="button" id="cancel-appointment" class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    Cancel appointment
                </button>
            </div>

            <details class="mt-6">
                <summary class="cursor-pointer text-sm font-medium text-gray-700">Reschedule</summary>
                <div class="mt-4">
                    <div
                        data-booking-widget
                        data-tenant-id="{{ $booking->tenant_id }}"
                        data-api-base="/api/v1"
                        data-tracking='@json($booking->tracking ?? [])'
                        data-reschedule-token="{{ $booking->manage_token }}"
                        data-invitee='@json($inviteeData)'
                    ></div>
                </div>
            </details>

            <script>
                document.getElementById('cancel-appointment').addEventListener('click', function () {
                    fetch('{{ route('manage.cancel', $booking->manage_token) }}', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    }).then(function () {
                        location.reload();
                    });
                });
            </script>
        @endif
    </section>
@endsection
