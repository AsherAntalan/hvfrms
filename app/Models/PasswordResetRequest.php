<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recovery request with an encrypted, request-specific email and hashed
 * verification/reset tokens. Approval follows email and in-person verification.
 */
class PasswordResetRequest extends Model
{
    public const STATUS_AWAITING_EMAIL = 'awaiting_email';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_USED = 'used';

    public const STATUS_REVOKED = 'revoked';

    public const VERIFICATION_METHODS = [
        'in_person' => 'In person',
        'callback' => 'Call',
    ];

    public const REJECTION_REASONS = [
        'identity_unverified' => 'Unable to verify identity',
        'not_requested' => 'Account holder did not request this reset',
        'contact_mismatch' => 'Request email or contact details could not be confirmed',
        'duplicate' => 'Duplicate recovery request',
        'cancelled' => 'Account holder cancelled the request',
    ];

    protected $fillable = [
        'is_admin_self_service',
        'manager_verified_by', 'manager_verified_at', 'manager_verification_method',
        'manager_verified_branch_id', 'manager_verified_role_id', 'manager_verified_email_hash',
        'rejection_reason',
        'request_contact_number',
        'request_email', 'verification_token_hash', 'verification_expires_at', 'email_verified_at', 'identity_verified_at', 'reset_token_hash',
        'user_id',
        'requested_by',
        'approved_by',
        'code_generated_by',
        'status',
        'code_hash',
        'approved_at',
        'rejected_at',
        'code_generated_at',
        'code_expires_at',
        'code_retrieved_at',
        'used_at',
        'revoked_at',
        'archived_at',
        'attempt_count',
    ];

    protected $hidden = [
        'manager_verified_email_hash',
        'request_contact_number',
        'request_email', 'verification_token_hash', 'reset_token_hash',
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_admin_self_service' => 'boolean',
            'manager_verified_at' => 'datetime',
            'request_contact_number' => 'encrypted',
            'request_email' => 'encrypted',
            'verification_expires_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'identity_verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'code_generated_at' => 'datetime',
            'code_expires_at' => 'datetime',
            'code_retrieved_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** Only closed requests may be permanently deleted. */
    public function isDeletable(): bool
    {
        return in_array($this->displayState(), [self::STATUS_USED, self::STATUS_REJECTED, self::STATUS_REVOKED, 'expired'], true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function managerVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_verified_by');
    }

    public function hasValidManagerVerification(): bool
    {
        return $this->manager_verified_at && $this->user && $this->managerVerifier
            && $this->managerVerifier->canVerifyResetFor($this->user)
            && (int) $this->manager_verified_branch_id === (int) $this->user->branch_id
            && (int) $this->manager_verified_role_id === (int) $this->user->role_id
            && hash_equals($this->manager_verified_email_hash ?? '', hash('sha256', $this->request_email ?? ''));
    }

    /**
     * A code exists, has not been used or revoked, and has not expired.
     */
    public function hasActiveCode(): bool
    {
        return $this->code_hash !== null
            && $this->status === self::STATUS_APPROVED
            && $this->code_expires_at !== null
            && $this->code_expires_at->isFuture();
    }

    /**
     * Display state for the management UI. An approved request whose code has not
     * been generated yet reports "approved" (no active code); once the code lapses
     * it reports "expired" without mutating the stored row.
     */
    public function displayState(): string
    {
        if ($this->status === self::STATUS_AWAITING_EMAIL && $this->verification_expires_at?->lessThanOrEqualTo(now())) {
            return 'expired';
        }
        if ($this->status === self::STATUS_APPROVED
            && $this->code_hash !== null
            && $this->code_expires_at
            && $this->code_expires_at->lessThanOrEqualTo(now())) {
            return 'expired';
        }

        return $this->status;
    }

    /**
     * True once an approved request has a code that has been generated at least
     * once (even if later expired) — used to decide reveal vs. replace wording.
     */
    public function codeWasGenerated(): bool
    {
        return $this->code_generated_at !== null;
    }
}
