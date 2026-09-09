<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Domain\Bookings\Booking;
use App\Domain\Webhooks\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DemoPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_page_mounts_widget_with_tracking(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $this->get('/demo')->assertOk()
            ->assertSee('data-booking-widget', false)
            ->assertSee('utm_content', false);
    }

    public function test_delivery_feed_returns_latest_deliveries_with_payload(): void
    {
        $delivery = WebhookDelivery::factory()->create(['response_status' => 200]);

        $this->getJson('/demo/feed/deliveries')->assertOk()
            ->assertJsonPath('data.0.event', 'booking.created')
            ->assertJsonPath('data.0.response_status', 200);
    }

    public function test_bookings_feed_returns_status_and_cancellation(): void
    {
        Booking::factory()->canceled()->create();

        $this->getJson('/demo/feed/bookings')->assertOk()
            ->assertJsonPath('data.0.status', 'canceled')
            ->assertJsonPath('data.0.cancellation_source', 'lead');
    }
}
