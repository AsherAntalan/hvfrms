<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_issues', function (Blueprint $table) {
            // 'receipt' = legacy per-line discrepancy confirmation; 'refund' =
            // branch-manager refund request described in a sentence + a photo.
            $table->string('type', 20)->default('receipt')->after('order_id');
            $table->string('photo_path', 255)->nullable()->after('receipt');
        });
    }

    public function down(): void
    {
        Schema::table('receiving_issues', function (Blueprint $table) {
            $table->dropColumn(['type', 'photo_path']);
        });
    }
};
