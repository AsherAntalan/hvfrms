<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('stock_batches', 'qr_code')) {
            Schema::table('stock_batches', function (Blueprint $table) {
                $table->string('qr_code', 100)->nullable()->after('created_by');
            });
        }
    }

    public function down(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->dropColumn('qr_code');
        });
    }
};
