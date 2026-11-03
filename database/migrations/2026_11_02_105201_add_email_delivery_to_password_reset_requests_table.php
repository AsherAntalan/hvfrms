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
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->text('request_email')->nullable();
            $table->string('verification_token_hash', 64)->nullable()->index();
            $table->dateTime('verification_expires_at')->nullable();
            $table->dateTime('email_verified_at')->nullable();
            $table->dateTime('identity_verified_at')->nullable();
            $table->string('reset_token_hash', 64)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropIndex(['verification_token_hash']);
            $table->dropIndex(['reset_token_hash']);
            $table->dropColumn(['request_email', 'verification_token_hash', 'verification_expires_at', 'email_verified_at', 'identity_verified_at', 'reset_token_hash']);
        });
    }
};
