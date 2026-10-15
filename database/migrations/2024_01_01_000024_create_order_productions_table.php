<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order-bound commissary production output. One row per order line: the actual
 * produced quantity, production time, expiry, condition, and a server-generated
 * unique order/production QR. Dispatch progress (dispatched_qty) is tracked here
 * separately from the order status, so a "For Delivery" label never permits
 * receipt before physical scan-out exists. Replaces faking a finished-stock
 * allocation for commissary orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_productions')) {
            return;
        }

        Schema::create('order_productions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('order_id')->unsigned();
            $table->integer('order_item_id')->unsigned()->unique(); // one output per line
            $table->integer('item_id')->unsigned();
            $table->integer('destination_branch_id')->unsigned();
            $table->integer('produced_qty')->unsigned()->default(0);
            $table->integer('dispatched_qty')->unsigned()->default(0);
            $table->dateTime('production_at')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('condition', 20)->default('good');
            $table->string('qr_code', 80)->unique();
            $table->integer('prepared_by')->unsigned()->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('order_item_id')->references('id')->on('order_items')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('destination_branch_id')->references('id')->on('branches');
            $table->foreign('prepared_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_productions');
    }
};
