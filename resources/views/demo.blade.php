@extends('layouts.app')

@section('title', 'Demo')

@section('content')
    <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
        <section class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold">Book a meeting</h2>

            <div class="mb-4 flex items-center gap-2">
                <span class="text-sm text-gray-500">Theme:</span>
                <button type="button" data-theme-color="#2563eb" class="h-6 w-6 rounded-full border border-gray-300" style="background-color:#2563eb" aria-label="Blue theme"></button>
                <button type="button" data-theme-color="#16a34a" class="h-6 w-6 rounded-full border border-gray-300" style="background-color:#16a34a" aria-label="Green theme"></button>
                <button type="button" data-theme-color="#9333ea" class="h-6 w-6 rounded-full border border-gray-300" style="background-color:#9333ea" aria-label="Purple theme"></button>
            </div>

            <div
                data-booking-widget
                data-tenant-id="{{ $tenant->id }}"
                data-api-base="/api/v1"
                data-tracking='@json($tracking)'
            ></div>
        </section>

        <div class="space-y-8">
            <section class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-lg font-semibold">Webhook inspector</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500">
                                <th class="py-2 pr-4">Event</th>
                                <th class="py-2 pr-4">URL</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Attempts</th>
                                <th class="py-2 pr-4">Delivered</th>
                                <th class="py-2 pr-4">Payload</th>
                            </tr>
                        </thead>
                        <tbody id="deliveries-body"></tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-lg font-semibold">Bookings</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500">
                                <th class="py-2 pr-4">Lead</th>
                                <th class="py-2 pr-4">Start</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Cancellation source</th>
                            </tr>
                        </thead>
                        <tbody id="bookings-body"></tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-theme-color]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.documentElement.style.setProperty('--color-primary', button.dataset.themeColor);
            });
        });

        function statusBadgeClass(status) {
            return status === 'canceled'
                ? 'inline-block rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800'
                : 'inline-block rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800';
        }

        function pollDeliveries() {
            fetch('{{ route('demo.feed.deliveries') }}')
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    var body = document.getElementById('deliveries-body');
                    body.textContent = '';

                    json.data.forEach(function (delivery) {
                        var row = document.createElement('tr');
                        row.className = 'border-b border-gray-100';

                        var eventCell = document.createElement('td');
                        eventCell.className = 'py-2 pr-4';
                        eventCell.textContent = delivery.event;
                        row.appendChild(eventCell);

                        var urlCell = document.createElement('td');
                        urlCell.className = 'py-2 pr-4';
                        urlCell.textContent = delivery.url;
                        row.appendChild(urlCell);

                        var statusCell = document.createElement('td');
                        statusCell.className = 'py-2 pr-4';
                        statusCell.textContent = delivery.response_status;
                        row.appendChild(statusCell);

                        var attemptsCell = document.createElement('td');
                        attemptsCell.className = 'py-2 pr-4';
                        attemptsCell.textContent = delivery.attempts;
                        row.appendChild(attemptsCell);

                        var deliveredCell = document.createElement('td');
                        deliveredCell.className = 'py-2 pr-4';
                        deliveredCell.textContent = delivery.delivered_at || '';
                        row.appendChild(deliveredCell);

                        var payloadCell = document.createElement('td');
                        payloadCell.className = 'py-2 pr-4';
                        var details = document.createElement('details');
                        var summary = document.createElement('summary');
                        summary.className = 'cursor-pointer text-blue-600';
                        summary.textContent = 'View';
                        var pre = document.createElement('pre');
                        pre.className = 'mt-1 whitespace-pre-wrap text-xs text-gray-700';
                        pre.textContent = JSON.stringify(delivery.payload, null, 2) + '\nsignature: ' + (delivery.signature || '');
                        details.appendChild(summary);
                        details.appendChild(pre);
                        payloadCell.appendChild(details);
                        row.appendChild(payloadCell);

                        body.appendChild(row);
                    });
                })
                .catch(function () {});
        }

        function pollBookings() {
            fetch('{{ route('demo.feed.bookings') }}')
                .then(function (response) { return response.json(); })
                .then(function (json) {
                    var body = document.getElementById('bookings-body');
                    body.textContent = '';

                    json.data.forEach(function (booking) {
                        var row = document.createElement('tr');
                        row.className = 'border-b border-gray-100';

                        var leadCell = document.createElement('td');
                        leadCell.className = 'py-2 pr-4';
                        leadCell.textContent = booking.lead;
                        row.appendChild(leadCell);

                        var startCell = document.createElement('td');
                        startCell.className = 'py-2 pr-4';
                        startCell.textContent = booking.start_time;
                        row.appendChild(startCell);

                        var statusCell = document.createElement('td');
                        statusCell.className = 'py-2 pr-4';
                        var badge = document.createElement('span');
                        badge.className = statusBadgeClass(booking.status);
                        badge.textContent = booking.status;
                        statusCell.appendChild(badge);
                        row.appendChild(statusCell);

                        var cancellationCell = document.createElement('td');
                        cancellationCell.className = 'py-2 pr-4';
                        cancellationCell.textContent = booking.cancellation_source || '';
                        row.appendChild(cancellationCell);

                        body.appendChild(row);
                    });
                })
                .catch(function () {});
        }

        pollDeliveries();
        pollBookings();
        setInterval(pollDeliveries, 3000);
        setInterval(pollBookings, 3000);
    </script>
@endsection
