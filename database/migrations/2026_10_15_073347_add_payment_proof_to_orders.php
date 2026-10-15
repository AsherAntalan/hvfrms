<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_reference', 40)->nullable()->unique();
            $table->string('payment_screenshot_path')->nullable();
            $table->dateTime('payment_submitted_at')->nullable();
            $table->json('proforma_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['payment_reference']);
            $table->dropColumn(['payment_reference', 'payment_screenshot_path', 'payment_submitted_at', 'proforma_snapshot']);
        });
    }
};
