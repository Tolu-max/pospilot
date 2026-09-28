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
        Schema::create('business_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('terminal_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->decimal('opening_cash', 15, 2)->nullable();
            $table->decimal('closing_cash', 15, 2)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index(['business_membership_id', 'status']);
            $table->index(['terminal_id', 'started_at', 'ended_at']);
        });

        Schema::create('business_shift_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_membership_id')->constrained()->cascadeOnDelete();
            $table->string('entry_type', 20);
            $table->decimal('amount', 15, 2);
            $table->string('category', 80);
            $table->string('description', 500)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['business_shift_id', 'recorded_at']);
        });

        Schema::create('business_shift_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_membership_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 160);
            $table->text('description');
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['business_shift_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_shift_issues');
        Schema::dropIfExists('business_shift_cash_entries');
        Schema::dropIfExists('business_shifts');
    }
};
