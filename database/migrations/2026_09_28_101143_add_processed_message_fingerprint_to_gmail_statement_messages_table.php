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
        Schema::table('gmail_statement_messages', function (Blueprint $table) {
            $table->string('processed_message_fingerprint', 64)->nullable()->after('dedupe_fingerprint');
            $table->index(['gmail_connection_id', 'processed_message_fingerprint'], 'gmail_processed_message_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gmail_statement_messages', function (Blueprint $table) {
            $table->dropIndex('gmail_processed_message_lookup');
            $table->dropColumn('processed_message_fingerprint');
        });
    }
};
