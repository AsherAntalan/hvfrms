<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ExpiredBatchArchive extends Model
{
    /** Days an archived entry stays visible before automatic removal. */
    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'batch_id',
        'branch_id',
        'archived_by',
        'archived_at',
        'removed_at',
        'removed_reason',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * The business-timezone cutoff: entries archived on/before this instant are
     * due for removal and must not appear in the archive list, even if the
     * scheduled cleanup has not yet run.
     */
    public static function retentionCutoff(): Carbon
    {
        return Carbon::now()->subDays(self::RETENTION_DAYS);
    }

    /**
     * Visible = not removed AND still inside the 30-day retention window.
     */
    public function scopeVisible($query)
    {
        return $query->whereNull('removed_at')
            ->where('archived_at', '>', self::retentionCutoff());
    }

    public function scheduledRemovalAt(): Carbon
    {
        return $this->archived_at->copy()->addDays(self::RETENTION_DAYS);
    }
}
