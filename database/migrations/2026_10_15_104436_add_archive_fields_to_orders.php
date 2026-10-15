<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'archived_at')) {
                $table->dateTime('archived_at')->nullable()->index();
            }
            if (! Schema::hasColumn('orders', 'archived_by')) {
                $table->unsignedInteger('archived_by')->nullable();
                $table->foreign('archived_by')->references('id')->on('users')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['archived_by']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'archived_by']);
        });
    }
};
