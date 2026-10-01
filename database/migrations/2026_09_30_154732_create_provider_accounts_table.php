<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->string('display_name');
            $table->string('account_type', 40)->default('mixed_personal_pos');
            $table->string('identifier_fingerprint', 64);
            $table->string('masked_identifier', 16)->nullable();
            $table->timestamps();
            $table->unique(['agent_profile_id', 'provider_id', 'identifier_fingerprint'], 'provider_account_identity_unique');
        });

        Schema::table('terminals', function (Blueprint $table): void {
            $table->foreignId('provider_account_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('provider_account_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('statement_mapping_profiles', function (Blueprint $table): void {
            $table->foreignId('provider_account_id')->nullable()->constrained()->nullOnDelete();
        });

        $pairs = collect()
            ->concat(DB::table('terminals')->select(['agent_profile_id', 'provider_id'])->get())
            ->concat(DB::table('transactions')->select(['agent_profile_id', 'provider_id'])->get())
            ->concat(DB::table('statement_mapping_profiles')->select(['agent_profile_id', 'provider_id'])->get())
            ->unique(fn (object $row): string => $row->agent_profile_id.'|'.$row->provider_id);

        foreach ($pairs as $pair) {
            $providerName = DB::table('providers')->where('id', $pair->provider_id)->value('name') ?? 'Provider';
            $accountType = DB::table('statement_mapping_profiles')
                ->where('agent_profile_id', $pair->agent_profile_id)
                ->where('provider_id', $pair->provider_id)
                ->value('account_type') ?? 'mixed_personal_pos';
            $accountId = DB::table('provider_accounts')->insertGetId([
                'agent_profile_id' => $pair->agent_profile_id,
                'provider_id' => $pair->provider_id,
                'display_name' => $providerName.' account',
                'account_type' => $accountType,
                'identifier_fingerprint' => hash('sha256', 'legacy-provider-account:'.$pair->agent_profile_id.':'.$pair->provider_id),
                'masked_identifier' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['terminals', 'transactions', 'statement_mapping_profiles'] as $table) {
                DB::table($table)
                    ->where('agent_profile_id', $pair->agent_profile_id)
                    ->where('provider_id', $pair->provider_id)
                    ->whereNull('provider_account_id')
                    ->update(['provider_account_id' => $accountId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('statement_mapping_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_account_id');
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_account_id');
        });
        Schema::table('terminals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('provider_account_id');
        });
        Schema::dropIfExists('provider_accounts');
    }
};
