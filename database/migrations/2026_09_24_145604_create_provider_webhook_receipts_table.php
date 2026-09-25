<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_connection_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 191);
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('processed');
            $table->timestamps();
            $table->unique(['provider_connection_id', 'event_id'], 'provider_webhook_connection_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_receipts');
    }
};
