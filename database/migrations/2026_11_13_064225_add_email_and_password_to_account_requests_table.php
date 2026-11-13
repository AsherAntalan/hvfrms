<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_requests', function (Blueprint $table) {
            // The requesting manager now supplies the employee's email and a
            // password, so no temporary password/first-login change is needed.
            $table->string('email', 255)->nullable()->after('full_name');
            $table->string('password_hash', 255)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('account_requests', function (Blueprint $table) {
            $table->dropColumn(['email', 'password_hash']);
        });
    }
};
