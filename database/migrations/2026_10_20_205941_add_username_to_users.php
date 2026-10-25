<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Add a login username, backfilling existing accounts from their email
     * local-part so no one is locked out. Login still accepts email as well.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username', 50)->nullable()->unique()->after('email');
            });
        }

        $taken = [];

        foreach (DB::table('users')->select('id', 'email', 'username')->get() as $user) {
            if (! empty($user->username)) {
                $taken[strtolower($user->username)] = true;

                continue;
            }

            $base = Str::slug(Str::before((string) $user->email, '@'), '') ?: 'user';
            $base = substr($base, 0, 40);
            $candidate = $base;
            $suffix = 1;

            while (isset($taken[strtolower($candidate)])) {
                $candidate = $base.$suffix;
                $suffix++;
            }

            $taken[strtolower($candidate)] = true;

            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('username');
            });
        }
    }
};
