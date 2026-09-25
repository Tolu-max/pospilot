<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('import_batch_id')->nullable()->after('terminal_id')->constrained()->nullOnDelete();
            $table->decimal('imported_customer_charge', 15, 2)->nullable()->after('customer_charge');
            $table->decimal('calculated_customer_charge', 15, 2)->nullable()->after('imported_customer_charge');
            $table->string('customer_charge_source')->default('calculated')->after('calculated_customer_charge');
            $table->string('import_fingerprint', 64)->nullable()->after('source');
            $table->unique(['agent_profile_id', 'provider_id', 'import_fingerprint'], 'transactions_import_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_import_identity_unique');
            $table->dropConstrainedForeignId('import_batch_id');
            $table->dropColumn(['imported_customer_charge', 'calculated_customer_charge', 'customer_charge_source', 'import_fingerprint']);
        });
    }
};
