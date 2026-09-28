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
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('business_membership_id')->nullable()->after('agent_profile_id')->constrained()->nullOnDelete();
            $table->foreignId('terminal_id')->nullable()->after('business_membership_id')->constrained()->nullOnDelete();
            $table->foreignId('business_shift_id')->nullable()->after('terminal_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_shift_id');
            $table->dropConstrainedForeignId('terminal_id');
            $table->dropConstrainedForeignId('business_membership_id');
        });
    }
};
