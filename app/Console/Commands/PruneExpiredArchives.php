<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ExpiredBatchArchive;
use Illuminate\Console\Command;

class PruneExpiredArchives extends Command
{
    protected $signature = 'archives:prune-expired';

    protected $description = 'Soft-remove expired-batch archive entries that are past the 30-day retention window (reporting lifecycle only — no inventory or audit data is deleted).';

    public function handle(): int
    {
        $cutoff = ExpiredBatchArchive::retentionCutoff();

        // Only still-visible entries past the window; repeat runs are safe
        // because removed rows are skipped by whereNull('removed_at').
        $due = ExpiredBatchArchive::whereNull('removed_at')
            ->where('archived_at', '<=', $cutoff)
            ->get();

        foreach ($due as $entry) {
            $entry->update([
                'removed_at' => now(),
                'removed_reason' => 'retention',
            ]);
        }

        if ($due->isNotEmpty()) {
            AuditLog::create([
                'user_id' => null,
                'action' => 'archive retention cleanup',
                'table_name' => 'expired_batch_archives',
                'record_id' => 0,
                'details' => "Auto-removed {$due->count()} archived expired-batch entr(ies) past {$cutoff->toDateTimeString()} (Asia/Manila). Inventory and audit history preserved.",
            ]);
        }

        $this->info("Pruned {$due->count()} archived expired-batch entr(ies) past the ".ExpiredBatchArchive::RETENTION_DAYS.'-day window.');

        return self::SUCCESS;
    }
}
