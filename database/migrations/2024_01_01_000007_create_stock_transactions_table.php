<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('item_id')->unsigned();
            $table->integer('branch_id')->unsigned();
            $table->integer('batch_id')->unsigned()->nullable();
            $table->enum('type', ['in', 'out', 'adjustment', 'expired', 'damaged']);
            $table->integer('quantity');
            $table->string('reason', 255)->nullable();
            $table->text('remarks')->nullable();
            $table->integer('performed_by')->unsigned()->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['item_id', 'branch_id'], 'idx_item_branch');
            $table->index(['created_at'], 'idx_date');

            $table->foreign('item_id')->references('id')->on('items');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('batch_id')->references('id')->on('stock_batches')->onDelete('set null');
            $table->foreign('performed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
