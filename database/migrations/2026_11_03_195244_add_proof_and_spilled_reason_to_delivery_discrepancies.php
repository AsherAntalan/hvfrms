<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REASONS_ORIGINAL = "'Missing Quantity','Damaged in Transit','Incorrect Quantity','Incorrect Item','Other'";

    public function up(): void
    {
        // Relax the DB-level enum to a plain string so new receipt reasons such
        // as "Damaged / Spilled" are permitted. Allowed values are enforced in
        // the application layer. Done cross-database via native column change.
        Schema::table('delivery_discrepancies', function (Blueprint $table): void {
            $table->string('reason', 50)->change();
        });

        Schema::table('delivery_discrepancies', function (Blueprint $table): void {
            if (! Schema::hasColumn('delivery_discrepancies', 'proof_path')) {
                $table->string('proof_path')->nullable()->after('remarks');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_discrepancies', function (Blueprint $table): void {
            if (Schema::hasColumn('delivery_discrepancies', 'proof_path')) {
                $table->dropColumn('proof_path');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE delivery_discrepancies SET reason = 'Damaged in Transit' WHERE reason = 'Damaged / Spilled'");
            DB::statement('ALTER TABLE delivery_discrepancies MODIFY reason ENUM('.self::REASONS_ORIGINAL.') NOT NULL');
        }
    }
};
