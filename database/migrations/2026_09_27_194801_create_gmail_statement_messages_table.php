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
        Schema::create('gmail_statement_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gmail_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('statement_mapping_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('dedupe_fingerprint', 64)->unique();
            $table->longText('gmail_message_id');
            $table->longText('gmail_attachment_id');
            $table->string('file_name')->nullable();
            $table->string('file_type', 20)->nullable();
            $table->json('headers')->nullable();
            $table->string('status')->default('discovered');
            $table->string('failure_code')->nullable();
            $table->string('temporary_file_path')->nullable();
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gmail_statement_messages');
    }
};
