<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_productions', function (Blueprint $table) {
            $table->unsignedInteger('stock_batch_id')->nullable()->unique();
            $table->foreign('stock_batch_id')->references('id')->on('stock_batches');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('labels_print_requested_at')->nullable();
        });
        Schema::create('order_production_units', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('production_id');
            $table->unsignedInteger('unit_number');
            $table->string('qr_code', 100)->unique();
            $table->dateTime('dispatched_at')->nullable();
            $table->unsignedInteger('dispatched_by')->nullable();
            $table->dateTime('created_at');
            $table->unique(['production_id', 'unit_number']);
            $table->foreign('production_id')->references('id')->on('order_productions');
            $table->foreign('dispatched_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_production_units');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('labels_print_requested_at'));
        Schema::table('order_productions', function (Blueprint $table) {
            $table->dropForeign(['stock_batch_id']);
            $table->dropColumn('stock_batch_id');
        });
    }
};
