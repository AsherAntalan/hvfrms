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
        Schema::create('account_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('requested_by');
            $table->unsignedInteger('branch_id');
            $table->string('full_name', 150);
            $table->string('username', 50)->unique();
            $table->string('requested_role', 40);
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('temporary_password')->nullable();
            $table->dateTime('credential_expires_at')->nullable();
            $table->dateTime('credential_revealed_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->foreign('requested_by')->references('id')->on('users');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('reviewed_by')->references('id')->on('users');
            $table->foreign('user_id')->references('id')->on('users');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
            $table->dateTime('temporary_password_expires_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_requests');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'temporary_password_expires_at']);
        });
    }
};
