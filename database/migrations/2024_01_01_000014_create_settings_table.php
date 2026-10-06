<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('setting_key', 100)->primary();
            $table->string('setting_value', 500);
            $table->string('description', 255)->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        DB::statement('ALTER TABLE settings MODIFY updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
