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
        if (! Schema::hasTable('business_memberships')) {
            Schema::create('business_memberships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('role', 20);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['agent_profile_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('business_invitations')) {
            Schema::create('business_invitations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_profile_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
                $table->string('email');
                $table->string('role', 20);
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['agent_profile_id', 'email', 'accepted_at']);
            });
        }

        if (! Schema::hasTable('business_membership_terminal')) {
            Schema::create('business_membership_terminal', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('business_membership_id')->constrained()->cascadeOnDelete();
                $table->foreignId('terminal_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['business_membership_id', 'terminal_id'], 'business_membership_terminal_pair_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_membership_terminal');
        Schema::dropIfExists('business_invitations');
        Schema::dropIfExists('business_memberships');
    }
};
