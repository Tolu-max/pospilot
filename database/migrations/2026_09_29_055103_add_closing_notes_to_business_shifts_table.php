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
        Schema::table('business_shifts', function (Blueprint $table): void {
            $table->text('closing_notes')->nullable()->after('closing_cash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_shifts', function (Blueprint $table): void {
            $table->dropColumn('closing_notes');
        });
    }
};
