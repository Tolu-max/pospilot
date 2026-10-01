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
        Schema::table('statement_mapping_profiles', function (Blueprint $table): void {
            $table->string('account_type', 40)->default('mixed_personal_pos')->after('provider_id');
            $table->json('known_pos_transaction_patterns')->nullable()->after('column_mapping');
            $table->json('excluded_patterns')->nullable()->after('known_pos_transaction_patterns');
            $table->string('confidence', 40)->default('unverified')->after('excluded_patterns');
            $table->string('classification_status', 40)->default('needs_review')->after('confidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statement_mapping_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'account_type',
                'known_pos_transaction_patterns',
                'excluded_patterns',
                'confidence',
                'classification_status',
            ]);
        });
    }
};
