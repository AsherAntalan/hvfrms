<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('item_name', 150);
            $table->integer('category_id')->unsigned()->nullable();
            $table->string('unit', 50)->default('pack');
            $table->string('pack_description', 100)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('image_path', 255)->nullable();
            $table->string('qr_code', 100)->nullable()->unique();
            $table->integer('reorder_level')->unsigned()->default(10);
            $table->integer('shelf_life_days')->unsigned()->nullable();
            $table->text('remarks')->nullable();
            $table->tinyInteger('is_active')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
