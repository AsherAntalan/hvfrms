<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->enum('type', ['bank', 'ewallet']);
            $table->string('provider', 60);        // BDO, BPI, GCash, PayMaya
            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('instructions', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};
