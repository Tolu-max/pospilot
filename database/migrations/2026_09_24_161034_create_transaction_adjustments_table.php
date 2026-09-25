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
        Schema::table('transactions', function (Blueprint $table): void {
            $table->boolean('provider_fee_components_complete')->default(false)->after('provider_fee');
        });

        Schema::create('transaction_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->decimal('amount', 15, 2);
            $table->string('direction', 8);
            $table->string('source', 16);
            $table->string('provider_component_code', 100)->nullable();
            $table->string('calculation_rule', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['transaction_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_adjustments');
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('provider_fee_components_complete');
        });
    }
};
