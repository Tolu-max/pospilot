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
        Schema::create('business_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('closing_reminder_enabled')->default(false);
            $table->boolean('daily_summary_enabled')->default(false);
            $table->boolean('issue_reminder_enabled')->default(false);
            $table->date('closing_reminder_sent_on')->nullable();
            $table->date('daily_summary_sent_on')->nullable();
            $table->date('issue_reminder_sent_on')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_notification_preferences');
    }
};
