<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('item_id')->unsigned();
            $table->integer('branch_id')->unsigned();
            $table->integer('quantity_in')->unsigned()->default(0);
            $table->integer('quantity_left')->unsigned()->default(0);
            $table->date('expiry_date')->nullable();
            $table->dateTime('received_at')->useCurrent();
            $table->integer('created_by')->unsigned()->nullable();

            $table->index(['item_id', 'branch_id', 'expiry_date', 'received_at'], 'idx_fifo');

            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
