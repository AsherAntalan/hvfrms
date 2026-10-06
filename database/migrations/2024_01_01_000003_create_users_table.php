<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('full_name', 100);
            $table->string('email', 150)->unique();
            $table->string('password_hash', 255);
            $table->string('profile_photo', 255)->nullable();
            $table->integer('role_id')->unsigned();
            $table->integer('branch_id')->unsigned();
            $table->enum('status', ['pending', 'active', 'disabled'])->default('pending');
            $table->tinyInteger('failed_attempts')->unsigned()->default(0);
            $table->dateTime('locked_until')->nullable();
            $table->string('reset_token', 64)->nullable();
            $table->dateTime('reset_token_expires')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('branch_id')->references('id')->on('branches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
