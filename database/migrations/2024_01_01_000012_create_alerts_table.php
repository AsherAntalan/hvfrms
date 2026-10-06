<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->increments('id');
            $table->enum('alert_type', ['low_stock', 'unusual_sales', 'expiring_soon', 'expired', 'operational']);
            $table->enum('severity', ['critical', 'warning', 'info'])->default('warning');
            $table->string('message', 500);
            $table->integer('item_id')->unsigned()->nullable();
            $table->integer('branch_id')->unsigned()->nullable();
            $table->integer('batch_id')->unsigned()->nullable();
            $table->tinyInteger('is_resolved')->default(0);
            $table->integer('resolved_by')->unsigned()->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->index(['is_resolved', 'created_at'], 'idx_open');

            $table->foreign('item_id')->references('id')->on('items')->onDelete('set null');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
            $table->foreign('batch_id')->references('id')->on('stock_batches')->onDelete('set null');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
