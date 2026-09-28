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
        Schema::create('gmail_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gmail_connection_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('access_token')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::table('gmail_connections', function (Blueprint $table): void {
            $table->string('gmail_address_masked', 255)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->string('last_error_code')->nullable();
        });

        Schema::table('gmail_statement_messages', function (Blueprint $table): void {
            $table->string('masked_account_identifier', 32)->nullable();
        });

        DB::table('gmail_connections')->select(['id', 'access_token', 'refresh_token', 'token_expires_at'])->orderBy('id')->chunkById(100, function ($connections): void {
            foreach ($connections as $connection) {
                if (! filled($connection->access_token)) {
                    continue;
                }

                DB::table('gmail_credentials')->insert([
                    'gmail_connection_id' => $connection->id,
                    'access_token' => $connection->access_token,
                    'refresh_token' => $connection->refresh_token,
                    'token_expires_at' => $connection->token_expires_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('gmail_connections', function (Blueprint $table): void {
            $table->dropColumn(['access_token', 'refresh_token', 'token_expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gmail_connections', function (Blueprint $table): void {
            $table->longText('access_token')->nullable();
            $table->longText('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
        });

        DB::table('gmail_credentials')->orderBy('id')->chunkById(100, function ($credentials): void {
            foreach ($credentials as $credential) {
                DB::table('gmail_connections')->where('id', $credential->gmail_connection_id)->update([
                    'access_token' => $credential->access_token,
                    'refresh_token' => $credential->refresh_token,
                    'token_expires_at' => $credential->token_expires_at,
                ]);
            }
        });

        Schema::table('gmail_connections', function (Blueprint $table): void {
            $table->dropColumn(['gmail_address_masked', 'connected_at', 'disconnected_at', 'last_error_code']);
        });

        Schema::table('gmail_statement_messages', function (Blueprint $table): void {
            $table->dropColumn('masked_account_identifier');
        });

        Schema::dropIfExists('gmail_credentials');
    }
};
