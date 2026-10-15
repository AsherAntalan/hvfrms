<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative per-batch condition.
 *
 * Until now a batch's health was only derivable from its expiry_date; there was
 * no way to record that a received lot was damaged. This adds a stored condition
 * so stock out / stock in can validate the real state of a batch (not a client
 * field): 'good' batches are issuable, 'damaged' batches are blocked from normal
 * stock in/out and must go through disposal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_batches', 'condition')) {
            Schema::table('stock_batches', function (Blueprint $table) {
                $table->string('condition', 20)->default('good')->after('expiry_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_batches', 'condition')) {
            Schema::table('stock_batches', function (Blueprint $table) {
                $table->dropColumn('condition');
            });
        }
    }
};
