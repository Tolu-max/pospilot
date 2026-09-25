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
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->string('onboarding_state')->default('not_started')->after('location');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('customer_charge_override_by')->nullable()->after('customer_charge_override')->constrained('users')->nullOnDelete();
            $table->timestamp('customer_charge_override_at')->nullable()->after('customer_charge_override_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn('onboarding_state');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_charge_override_by');
            $table->dropColumn('customer_charge_override_at');
        });
    }
};
