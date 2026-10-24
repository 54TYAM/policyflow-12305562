<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')
                ->constrained('policy_requests')
                ->cascadeOnDelete();

            // Idempotency — prevents duplicate callback processing
            $table->string('event_id', 100)->unique();
            $table->string('event_type', 50); // POLICY_ISSUED, POLICY_FAILED, etc.
            $table->string('provider_reference', 255)->nullable();
            $table->string('policy_number', 100)->nullable();
            $table->text('payload')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
