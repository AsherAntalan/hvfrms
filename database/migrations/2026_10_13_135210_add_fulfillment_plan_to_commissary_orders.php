<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('fulfilled_quantity')->nullable();
            $table->string('fulfillment_reason', 500)->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('fulfilled_amount', 12, 2)->nullable();
        });
        Schema::table('order_productions', function (Blueprint $table) {
            $table->string('source_type', 20)->default('produced');
        });
        Schema::table('order_production_units', function (Blueprint $table) {
            $table->unsignedInteger('stock_batch_id')->nullable();
            $table->foreign('stock_batch_id')->references('id')->on('stock_batches');
            $table->index(['stock_batch_id', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_production_units', function (Blueprint $table) {
            $table->dropForeign(['stock_batch_id']);
            $table->dropIndex(['stock_batch_id', 'dispatched_at']);
            $table->dropColumn('stock_batch_id');
        });
        Schema::table('order_productions', fn (Blueprint $table) => $table->dropColumn('source_type'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('fulfilled_amount'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['fulfilled_quantity', 'fulfillment_reason']));
    }
};
