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
        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->date('closing_date');
            $table->decimal('opening_cash', 15, 2)->nullable();
            $table->decimal('entered_closing_cash', 15, 2)->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('expected_electronic_position', 15, 2)->default(0);
            $table->decimal('actual_electronic_position', 15, 2)->nullable();
            $table->decimal('transaction_volume', 15, 2)->default(0);
            $table->decimal('customer_charges', 15, 2)->default(0);
            $table->decimal('provider_fees', 15, 2)->default(0);
            $table->decimal('expenses', 15, 2)->default(0);
            $table->decimal('total_variance', 15, 2)->default(0);
            $table->string('status')->default('draft');
            $table->string('variance_status')->default('unresolved');
            $table->text('notes')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->unique(['agent_profile_id', 'closing_date']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_closings');
    }
};
