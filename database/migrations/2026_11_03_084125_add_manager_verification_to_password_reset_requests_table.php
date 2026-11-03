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
            $table->unsignedInteger('manager_verified_by')->nullable();
            $table->dateTime('manager_verified_at')->nullable();
            $table->string('manager_verification_method', 30)->nullable();
            $table->unsignedInteger('manager_verified_branch_id')->nullable();
            $table->unsignedInteger('manager_verified_role_id')->nullable();
            $table->string('manager_verified_email_hash', 64)->nullable();
            $table->foreign('manager_verified_by')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropForeign(['manager_verified_by']);
            $table->dropColumn(['manager_verified_by', 'manager_verified_at', 'manager_verification_method', 'manager_verified_branch_id', 'manager_verified_role_id', 'manager_verified_email_hash']);
        });
    }
};
