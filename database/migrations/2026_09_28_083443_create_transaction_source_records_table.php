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
        Schema::create('transaction_source_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->string('source_reference_fingerprint', 64);
            $table->string('metadata_fingerprint', 64);
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['transaction_id', 'source_type', 'source_reference_fingerprint'], 'transaction_source_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_source_records');
    }
};
