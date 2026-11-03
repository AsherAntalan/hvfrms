<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('receiving_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('reported_by');
            $table->unsignedInteger('resolved_by')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->json('receipt');
            $table->json('snapshot');
            $table->text('resolution_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('reported_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['order_id', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receiving_issues');
    }
};
