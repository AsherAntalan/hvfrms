<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_recommendations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('item_id')->unsigned();
            $table->date('week_start');
            $table->decimal('avg_weekly', 8, 2)->default(0);
            $table->integer('forecast_qty')->default(0);
            $table->integer('recommend_qty')->default(0);
            $table->enum('pattern', ['rising', 'falling', 'stable', 'flat', 'insufficient'])->default('stable');
            $table->dateTime('created_at')->nullable();

            $table->unique(['item_id', 'week_start'], 'uq_forecast');

            $table->foreign('item_id')->references('id')->on('items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_recommendations');
    }
};
