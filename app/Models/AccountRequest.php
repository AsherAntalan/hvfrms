<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountRequest extends Model
{
    protected $fillable = [
        'requested_by', 'branch_id', 'full_name', 'username', 'requested_role', 'status',
        'reviewed_by', 'user_id', 'rejection_reason', 'temporary_password',
        'credential_expires_at', 'credential_revealed_at', 'reviewed_at',
    ];

    protected $hidden = ['temporary_password'];

    protected function casts(): array
    {
        return [
            'temporary_password' => 'encrypted',
            'credential_expires_at' => 'datetime',
            'credential_revealed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
