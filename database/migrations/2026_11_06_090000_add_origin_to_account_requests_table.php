<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record how a credential-delivery entry originated so the reveal/replace
     * authorization can branch without inventing a fake manager requester:
     *   'manager_request' — a branch/commissary manager requested a staff account
     *       that an authorizing admin approved (the existing flow; the requesting
     *       manager reveals the one-time credentials).
     *   'admin_created'   — an authorizing admin created the account directly from
     *       the Users page; an authorizing admin reveals the one-time credentials.
     */
    public function up(): void
    {
        Schema::table('account_requests', function (Blueprint $table) {
            $table->string('origin', 20)->default('manager_request')->after('requested_role');
        });
    }

    public function down(): void
    {
        Schema::table('account_requests', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
