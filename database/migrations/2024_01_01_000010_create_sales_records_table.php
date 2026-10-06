<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('item_id')->unsigned();
            $table->integer('branch_id')->unsigned();
            $table->integer('qty_sold')->unsigned()->default(0);
            $table->date('sale_date');
            $table->integer('recorded_by')->unsigned()->nullable();
            $table->dateTime('created_at')->nullable();

            $table->unique(['item_id', 'branch_id', 'sale_date'], 'uq_sale');

            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_records');
    }
};
