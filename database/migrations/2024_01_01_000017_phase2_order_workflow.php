<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','approved','preparing','delivering','delivered','settled','cancelled') NOT NULL DEFAULT 'pending'");

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('prepared_by')->nullable()->after('approved_at');
            $table->timestamp('prepared_at')->nullable()->after('prepared_by');
            $table->unsignedInteger('confirmed_by')->nullable()->after('delivered_at');
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('received_quantity')->nullable()->after('unit_price');
        });

        Schema::create('delivery_discrepancies', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('expected_quantity');
            $table->unsignedInteger('received_quantity');
            $table->enum('reason', [
                'Missing Quantity',
                'Damaged in Transit',
                'Incorrect Quantity',
                'Incorrect Item',
                'Other',
            ]);
            $table->text('remarks')->nullable();
            $table->unsignedInteger('reported_by');
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('order_item_id')->references('id')->on('order_items');
            $table->foreign('reported_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_discrepancies');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('received_quantity');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['prepared_by', 'prepared_at', 'confirmed_by', 'confirmed_at']);
        });

        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','approved','delivering','delivered','settled','cancelled') NOT NULL DEFAULT 'pending'");
    }
};
