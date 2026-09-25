<?php

use App\Enums\SettlementStatus;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_reference')->nullable()->index();
            $table->string('transaction_type');
            $table->decimal('amount', 15, 2);
            $table->decimal('customer_charge', 15, 2)->default(0);
            $table->decimal('customer_charge_override', 15, 2)->nullable();
            $table->decimal('provider_fee', 15, 2)->default(0);
            $table->string('transaction_status')->default(TransactionStatus::Successful->value);
            $table->string('settlement_status')->default(SettlementStatus::Pending->value);
            $table->timestamp('transaction_at');
            $table->timestamp('settled_at')->nullable();
            $table->string('source')->default(TransactionSource::Demo->value);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['agent_profile_id', 'transaction_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
