<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->text('webhook_secret')->nullable()->after('credentials');
            $table->timestamp('last_webhook_at')->nullable()->after('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('provider_connections', function (Blueprint $table): void {
            $table->dropColumn(['webhook_secret', 'last_webhook_at']);
        });
    }
};
