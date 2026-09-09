<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Domain\Bookings\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ManageBookingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_manage_page_shows_booking_and_actions(): void
    {
        $booking = Booking::factory()->create();

        $this->get("/manage/{$booking->manage_token}")->assertOk()
            ->assertSee($booking->tenant->name)
            ->assertSee('Cancel appointment')
            ->assertSee('data-reschedule-token="'.$booking->manage_token.'"', false);
    }

    public function test_canceled_booking_shows_terminal_state_without_actions(): void
    {
        $booking = Booking::factory()->canceled()->create();

        $this->get("/manage/{$booking->manage_token}")->assertOk()
            ->assertSee('This appointment was canceled')
            ->assertDontSee('Cancel appointment');
    }
}
