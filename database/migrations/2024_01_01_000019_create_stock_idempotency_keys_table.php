<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency guard for stock movements.
 *
 * A legitimate stock in/out submission carries a unique client-generated key.
 * The key is inserted inside the same DB transaction that records the movement;
 * a duplicate submission (double click, retry, replayed request) collides on the
 * unique index and is rejected without recording a second movement. A brand-new
 * movement simply uses a fresh key, so repeat transactions stay possible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_idempotency_keys')) {
            return;
        }

        Schema::create('stock_idempotency_keys', function (Blueprint $table) {
            $table->increments('id');
            $table->string('idempotency_key', 64)->unique();
            $table->string('scope', 40)->nullable();      // e.g. stock_in / stock_out
            $table->integer('performed_by')->unsigned()->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_idempotency_keys');
    }
};
