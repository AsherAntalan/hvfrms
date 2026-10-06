<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('order_number', 30)->unique();
            $table->integer('branch_id')->unsigned();
            $table->enum('status', ['pending', 'approved', 'delivering', 'delivered', 'settled', 'cancelled'])->default('pending');
            $table->integer('ordered_by')->unsigned()->nullable();
            $table->integer('approved_by')->unsigned()->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('ordered_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
