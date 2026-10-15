<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting-only archive for expired stock batches.
 *
 * Archiving is a REPORTING lifecycle, not disposal: the underlying stock_batch
 * (quantity_left, expiry, branch, ledger links) is left completely untouched so
 * Stock Summary stays truthful and the goods remain blocked from stock in/out.
 * This table only records that an expired batch row was moved out of the active
 * "pending review" expiry list.
 *
 * Retention: an entry is visible for 30 days from `archived_at`. `removed_at`
 * soft-removes it — set either by an early manual Delete or by the scheduled
 * cleanup for entries past the 30-day boundary. Rows are never hard-deleted, so
 * audit evidence of the archive/removal is preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expired_batch_archives')) {
            return;
        }

        Schema::create('expired_batch_archives', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('batch_id')->unsigned()->unique();
            $table->integer('branch_id')->unsigned();
            $table->integer('archived_by')->unsigned()->nullable();
            $table->dateTime('archived_at');
            $table->dateTime('removed_at')->nullable();
            $table->string('removed_reason', 40)->nullable(); // 'manual' | 'retention'
            $table->timestamps();

            $table->index(['removed_at', 'archived_at']);
            $table->index('branch_id');

            $table->foreign('batch_id')->references('id')->on('stock_batches')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('archived_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expired_batch_archives');
    }
};
