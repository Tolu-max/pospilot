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
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('entity_type')->default('transaction')->after('source');
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('terminal_id')->constrained()->nullOnDelete();
            $table->decimal('gross_transaction_amount', 15, 2)->nullable()->after('settlement_reference');
            $table->decimal('provider_fee', 15, 2)->default(0)->after('gross_transaction_amount');
            $table->string('source')->default('manual')->after('status');
            $table->string('import_fingerprint', 64)->nullable()->after('source');
            $table->unique(['agent_profile_id', 'provider_id', 'import_fingerprint'], 'settlements_import_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropUnique('settlements_import_identity_unique');
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn(['gross_transaction_amount', 'provider_fee', 'source', 'import_fingerprint']);
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('entity_type');
        });
    }
};
