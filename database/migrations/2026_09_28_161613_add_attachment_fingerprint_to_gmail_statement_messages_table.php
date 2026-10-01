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
        Schema::table('gmail_statement_messages', function (Blueprint $table): void {
            $table->string('attachment_fingerprint', 64)->nullable()->after('dedupe_fingerprint')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gmail_statement_messages', function (Blueprint $table): void {
            $table->dropIndex(['attachment_fingerprint']);
            $table->dropColumn('attachment_fingerprint');
        });
    }
};
