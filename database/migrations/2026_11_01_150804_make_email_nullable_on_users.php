<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts may now exist without an email. The column keeps its length and
     * unique index (MySQL permits multiple NULLs under a unique index), so stored
     * addresses are preserved and only the NOT NULL requirement is removed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 150)->nullable(false)->change();
        });
    }
};
