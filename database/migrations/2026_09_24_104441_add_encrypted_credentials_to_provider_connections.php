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
        Schema::table('provider_connections', function (Blueprint $table) {
            $table->text('credentials')->nullable()->after('provider_merchant_identifier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table) {
            $table->dropColumn('credentials');
        });
    }
};
