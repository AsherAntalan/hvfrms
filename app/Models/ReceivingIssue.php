<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivingIssue extends Model
{
    protected $fillable = [
        'order_id', 'reported_by', 'resolved_by', 'status', 'reason',
        'receipt', 'snapshot', 'resolution_reason', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['receipt' => 'array', 'snapshot' => 'array', 'resolved_at' => 'datetime'];
    }
}
