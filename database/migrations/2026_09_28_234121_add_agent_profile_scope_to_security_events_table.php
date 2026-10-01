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
        Schema::table('security_events', function (Blueprint $table) {
            $table->foreignId('agent_profile_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['agent_profile_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropIndex(['agent_profile_id', 'occurred_at']);
            $table->dropConstrainedForeignId('agent_profile_id');
        });
    }
};
