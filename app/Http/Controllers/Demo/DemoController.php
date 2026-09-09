<?php

namespace App\Http\Controllers\Demo;

use Illuminate\Support\Str;
use App\Domain\Tenants\Tenant;
use App\Domain\Bookings\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use App\Domain\Webhooks\WebhookDelivery;

class DemoController extends Controller
{
    public function show(): View
    {
        $tenant = Tenant::firstOrFail();

        $tracking = [
            'utm_source' => 'ULH',
            'utm_medium' => 'demo',
            'utm_content' => 'demo-lead-'.Str::random(20),
        ];

        return view('demo', [
            'tenant' => $tenant,
            'tracking' => $tracking,
        ]);
    }

    public function deliveries(): JsonResponse
    {
        $deliveries = WebhookDelivery::with('endpoint')->latest()->limit(20)->get();

        return response()->json([
            'data' => $deliveries->map(fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'event' => $delivery->event,
                'url' => $delivery->endpoint?->url,
                'payload' => $delivery->payload,
                'signature' => $delivery->signature,
                'response_status' => $delivery->response_status,
                'attempts' => $delivery->attempts,
                'delivered_at' => $delivery->delivered_at,
                'created_at' => $delivery->created_at,
            ]),
        ]);
    }

    public function bookings(): JsonResponse
    {
        $bookings = Booking::latest()->limit(20)->get();

        return response()->json([
            'data' => $bookings->map(fn (Booking $booking): array => [
                'uuid' => $booking->uuid,
                'lead' => $booking->leadFullName(),
                'start_time' => $booking->starts_at,
                'status' => $booking->status,
                'cancellation_source' => $booking->cancellation_source,
                'created_at' => $booking->created_at,
            ]),
        ]);
    }
}
