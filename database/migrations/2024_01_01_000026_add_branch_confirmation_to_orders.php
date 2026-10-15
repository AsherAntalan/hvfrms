<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destination-branch confirmation of an approved, produced shipment — a distinct
 * acknowledgement that happens BEFORE physical dispatch and must not reuse the
 * receipt fields (confirmed_by/at), whose meaning is "goods received".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'branch_confirmed_by')) {
                $table->integer('branch_confirmed_by')->unsigned()->nullable()->after('cancel_reason');
            }
            if (! Schema::hasColumn('orders', 'branch_confirmed_at')) {
                $table->dateTime('branch_confirmed_at')->nullable()->after('branch_confirmed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['branch_confirmed_by', 'branch_confirmed_at'] as $c) {
                if (Schema::hasColumn('orders', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
