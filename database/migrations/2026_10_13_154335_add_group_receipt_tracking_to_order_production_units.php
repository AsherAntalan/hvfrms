<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_production_units', function (Blueprint $table) {
            $table->dateTime('received_at')->nullable();
            $table->unsignedInteger('received_by')->nullable();
            $table->boolean('receipt_accepted')->default(false);
            $table->foreign('received_by')->references('id')->on('users');
        });
        // Previously completed orders must never become receivable again.
        DB::table('order_production_units as u')->join('order_productions as p', 'p.id', '=', 'u.production_id')
            ->join('orders as o', 'o.id', '=', 'p.order_id')->join('order_items as i', 'i.id', '=', 'p.order_item_id')
            ->whereIn('o.status', ['delivered', 'settled'])->whereNotNull('u.dispatched_at')
            ->select('u.id', 'u.unit_number', 'o.confirmed_at', 'o.delivered_at', 'o.confirmed_by', 'i.received_quantity')
            ->orderBy('u.id')->chunk(200, function ($units) {
                foreach ($units as $unit) {
                    DB::table('order_production_units')->where('id', $unit->id)->update([
                        'received_at' => $unit->confirmed_at ?? $unit->delivered_at ?? now(), 'received_by' => $unit->confirmed_by,
                        'receipt_accepted' => $unit->unit_number <= (int) $unit->received_quantity,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_production_units', function (Blueprint $table) {
            $table->dropForeign(['received_by']);
            $table->dropColumn(['received_at', 'received_by', 'receipt_accepted']);
        });
    }
};
