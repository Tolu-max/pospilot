<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gmail_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('access_token');
            $table->longText('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('provider_rules')->nullable();
            $table->string('status')->default('connected');
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_connections');
    }
};
