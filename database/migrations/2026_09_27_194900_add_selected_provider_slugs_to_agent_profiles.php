<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->json('selected_provider_slugs')->nullable();
            $table->json('statement_sender_rules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table) {
            $table->dropColumn('selected_provider_slugs');
            $table->dropColumn('statement_sender_rules');
        });
    }
};
