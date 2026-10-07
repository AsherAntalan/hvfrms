<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','approved','preparing','delivering','delivered','settled','cancelled') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'prepared_by')) {
                $table->unsignedInteger('prepared_by')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('orders', 'prepared_at')) {
                $table->timestamp('prepared_at')->nullable()->after('prepared_by');
            }
            if (!Schema::hasColumn('orders', 'confirmed_by')) {
                $table->unsignedInteger('confirmed_by')->nullable()->after('delivered_at');
            }
            if (!Schema::hasColumn('orders', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            }
        });

        if (!Schema::hasColumn('order_items', 'received_quantity')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->unsignedInteger('received_quantity')->nullable()->after('unit_price');
            });
        }

        if (!Schema::hasTable('delivery_discrepancies')) {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_discrepancies');

        if (Schema::hasColumn('order_items', 'received_quantity')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('received_quantity');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $cols = [];
            foreach (['prepared_by', 'prepared_at', 'confirmed_by', 'confirmed_at'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $cols[] = $col;
                }
            }
            if ($cols) {
                $table->dropColumn($cols);
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending','approved','delivering','delivered','settled','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
