<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')
                ->constrained('policy_requests')
                ->cascadeOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('trigger', 100)->nullable(); // what caused the transition
            $table->text('metadata')->nullable(); // additional context as JSON
            $table->string('actor', 100)->nullable(); // system, agent, callback, etc.

            $table->timestamp('transitioned_at')->useCurrent();
            $table->timestamps();

            $table->index('policy_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
