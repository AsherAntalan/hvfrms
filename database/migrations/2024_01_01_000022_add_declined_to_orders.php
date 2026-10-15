<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a distinct "declined" order status (approver rejection of a pending
 * order) plus its metadata. Widening status to a plain string removes the old
 * enum/CHECK constraint so the new value is accepted on both MySQL and SQLite;
 * valid transitions remain enforced in application code. Additive and
 * data-preserving — existing orders and their statuses are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();

            if (! Schema::hasColumn('orders', 'declined_by')) {
                $table->unsignedInteger('declined_by')->nullable()->after('confirmed_at');
            }
            if (! Schema::hasColumn('orders', 'declined_at')) {
                $table->dateTime('declined_at')->nullable()->after('declined_by');
            }
            if (! Schema::hasColumn('orders', 'decline_reason')) {
                $table->string('decline_reason', 500)->nullable()->after('declined_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['declined_by', 'declined_at', 'decline_reason'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
