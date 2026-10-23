<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations to join a household (RN-39). Only the SHA-256 hash of the token is stored: the
     * link is shown once, when it is created. An invitation is pending until it is accepted,
     * declined, revoked or expires; those dates keep its history.
     */
    public function up(): void
    {
        Schema::create('household_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 10);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['household_id', 'email']);
            $table->index('email');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE household_invitations ADD CONSTRAINT household_invitations_role_valid CHECK (role IN ('admin', 'editor', 'viewer'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('household_invitations');
    }
};
