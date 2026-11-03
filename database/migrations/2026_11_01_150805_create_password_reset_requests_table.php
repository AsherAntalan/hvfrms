<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hierarchical password-recovery requests. Each row binds one reset to one
     * target account and (once approved) to one single-use code. Only a SHA-256
     * hash of the code is stored — never plaintext. Pending rows carry no code
     * and no expiry; the one-hour timer starts when the code is generated.
     */
    public function up(): void
    {
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();                 // target account
            $table->integer('requested_by')->unsigned();            // deliverer (manager for staff; authorizing actor for direct resets)
            $table->integer('approved_by')->unsigned()->nullable(); // authorizing actor
            $table->integer('code_generated_by')->unsigned()->nullable();
            $table->string('status', 20)->default('pending');       // pending, approved, rejected, used, revoked
            $table->string('code_hash', 64)->nullable();            // sha256 of the normalized code
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('code_generated_at')->nullable();
            $table->dateTime('code_expires_at')->nullable();
            $table->dateTime('code_retrieved_at')->nullable();      // one-time plaintext display timestamp
            $table->dateTime('used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->integer('attempt_count')->unsigned()->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
