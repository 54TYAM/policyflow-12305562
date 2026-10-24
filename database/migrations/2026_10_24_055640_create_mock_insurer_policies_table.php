<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference', 255)->unique();
            $table->string('client_request_id', 100);
            $table->string('policy_number', 100)->unique();
            $table->string('status', 30)->default('ISSUED'); // ISSUED, PENDING, FAILED
            $table->decimal('premium', 12, 2)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_policies');
    }
};
