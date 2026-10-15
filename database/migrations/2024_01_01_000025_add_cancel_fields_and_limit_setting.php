<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requester cancellation metadata (distinct from approver Decline), plus the
 * owner-configurable per-line order quantity limit (0 = unlimited; no numeric
 * policy is active until an owner sets a value in Settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'cancelled_by')) {
                $table->integer('cancelled_by')->unsigned()->nullable()->after('decline_reason');
            }
            if (! Schema::hasColumn('orders', 'cancelled_at')) {
                $table->dateTime('cancelled_at')->nullable()->after('cancelled_by');
            }
            if (! Schema::hasColumn('orders', 'cancel_reason')) {
                $table->string('cancel_reason', 500)->nullable()->after('cancelled_at');
            }
        });

        if (! DB::table('settings')->where('setting_key', 'order_max_qty_per_line')->exists()) {
            DB::table('settings')->insert([
                'setting_key' => 'order_max_qty_per_line',
                'setting_value' => '0',
                'description' => 'Maximum quantity per product line on an order (0 = unlimited). Owner-configurable.',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['cancelled_by', 'cancelled_at', 'cancel_reason'] as $c) {
                if (Schema::hasColumn('orders', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        DB::table('settings')->where('setting_key', 'order_max_qty_per_line')->delete();
    }
};
