<?php

use App\Enums\ProviderConnectionStatus;
use App\Enums\ProviderConnectionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('connection_type')->default(ProviderConnectionType::Csv->value);
            $table->string('connection_status')->default(ProviderConnectionStatus::Inactive->value);
            $table->string('provider_account_identifier')->nullable();
            $table->string('provider_merchant_identifier')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['agent_profile_id', 'provider_id', 'connection_type'], 'provider_connections_agent_provider_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_connections');
    }
};
