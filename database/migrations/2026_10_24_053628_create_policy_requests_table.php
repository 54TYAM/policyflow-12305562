<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Idempotency key — prevents duplicate logical requests
            $table->string('agent_id', 50);
            $table->string('client_request_id', 100);

            // Customer info
            $table->string('customer_name', 255);
            $table->date('date_of_birth');

            // Trip details
            $table->string('destination', 20); // DOMESTIC or INTERNATIONAL
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 20); // BASIC, PLUS, PREMIUM
            $table->unsignedBigInteger('sum_insured'); // in smallest unit (paise/cents)

            // Premium snapshot — immutable once calculated
            $table->decimal('premium', 12, 2)->nullable();
            $table->unsignedInteger('travel_days')->nullable();
            $table->decimal('age_multiplier', 4, 2)->nullable();
            $table->decimal('destination_multiplier', 4, 2)->nullable();
            $table->decimal('coverage_multiplier', 4, 2)->nullable();
            $table->decimal('base_daily_rate', 8, 2)->nullable();

            // Workflow state
            $table->string('status', 30)->default('RECEIVED');
            $table->string('policy_number', 100)->nullable()->unique();
            $table->string('provider_reference', 255)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_provider_response')->nullable();
            $table->text('error_details')->nullable();

            $table->timestamps();

            // Enforce idempotency at DB level
            $table->unique(['agent_id', 'client_request_id'], 'uq_agent_client_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
