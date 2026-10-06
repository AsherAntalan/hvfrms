<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('order_id')->unsigned();
            $table->integer('item_id')->unsigned();
            $table->integer('quantity')->unsigned()->default(1);
            $table->decimal('unit_price', 10, 2)->default(0);

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('item_id')->references('id')->on('items');
        });

        DB::statement('ALTER TABLE order_items ADD COLUMN subtotal DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
