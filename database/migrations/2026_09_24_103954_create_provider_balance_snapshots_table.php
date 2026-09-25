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
        Schema::create('provider_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_closing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('expected_balance', 15, 2);
            $table->decimal('actual_balance', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();
            $table->string('source')->default('manual');
            $table->json('metadata')->nullable();
            $table->unique(['daily_closing_id', 'provider_id', 'terminal_id'], 'closing_provider_terminal_unique');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_balance_snapshots');
    }
};
