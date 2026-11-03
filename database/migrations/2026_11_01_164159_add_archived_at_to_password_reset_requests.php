<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archive flag for closed reset requests so they can be cleared from the
     * active list and later permanently deleted.
     */
    public function up(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dateTime('archived_at')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
