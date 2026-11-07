<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedSmallInteger('shelf_life_months')->nullable();
        });

        $sixMonthCategories = DB::table('categories')
            ->whereIn('name', ['Sauces', 'Sauces (Big Sizes)', 'Bread', 'Frozen Products', 'Frozen Food'])->pluck('id');
        DB::table('items')->whereIn('category_id', $sixMonthCategories)
            ->update(['shelf_life_months' => 6]);
        $juiceCategories = DB::table('categories')->whereIn('name', ['Juice', 'Juices', 'Juices/Others'])->pluck('id');
        DB::table('items')->whereIn('category_id', $juiceCategories)
            ->whereIn('item_name', ['Blueberry Juice', 'Strawberry Juice', 'Kiwi Juice', 'Lychee Juice', 'Lemon Juice', 'Honey Peach Juice', 'Passion Fruit Juice', 'Green Apple Juice'])
            ->update(['shelf_life_months' => 12]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('shelf_life_months');
        });
    }
};
