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
        Schema::create('statement_mapping_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_type', 20);
            $table->char('schema_fingerprint', 64);
            $table->char('match_identifier_fingerprint', 64)->nullable();
            $table->string('masked_match_identifier', 32)->nullable();
            $table->json('column_mapping');
            $table->string('status')->default('active');
            $table->timestamp('last_successful_use_at')->nullable();
            $table->index(
                ['agent_profile_id', 'provider_id', 'schema_fingerprint'],
                'statement_mapping_profiles_agent_provider_schema_idx'
            );
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statement_mapping_profiles');
    }
};
