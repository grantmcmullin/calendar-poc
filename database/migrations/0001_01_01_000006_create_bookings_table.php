<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class() extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_event_id')->nullable();
            $table->string('provider_event_link')->nullable();
            $table->string('lead_first_name');
            $table->string('lead_last_name');
            $table->string('lead_email');
            $table->string('lead_phone');
            $table->string('lead_timezone');
            $table->json('tracking')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('confirmed');
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancellation_source')->nullable();
            $table->string('manage_token', 64)->unique();
            $table->foreignId('rescheduled_from_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
