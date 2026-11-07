<?php

namespace App\Services;

use App\Mail\PasswordResetMail;
use App\Mail\RecoveryEmailVerificationMail;
use App\Models\AccountRequest;
use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Models\RememberToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class EmailRecoveryService
{
    public function __construct(private ResetCodeService $codes) {}

    public function request(string $username, string $code, ?string $email = null, ?string $contactNumber = null): void
    {
        $key = 'email-recovery:'.hash('sha256', strtolower($username));
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return;
        }
        RateLimiter::hit($key, 900);
        $normalized = $this->codes->normalize($code);
        if (! $this->codes->isWellFormed($normalized)) {
            return;
        }
        DB::transaction(function () use ($username, $normalized, $email, $contactNumber): void {
            $user = User::with('role')->where('username', $username)->where('status', 'active')->lockForUpdate()->first();
            if (! $user || $user->retired_at || $user->isSuperAdmin() || ! hash_equals($user->recovery_code_hash ?? '', $this->codes->hash($normalized))) {
                return;
            }
            if ($user->isAdmin()) {
                // Auto self-service only when the submitted personal email matches the
                // admin's own registered email. The link is always sent to the
                // registered email; a non-matching email triggers nothing.
                if ($email !== null && $user->email !== null
                    && hash_equals(strtolower($user->email), strtolower(trim($email)))) {
                    $this->requestAdminReset($user);
                }

                return;
            }
            if (! $email) {
                return;
            }
            $this->revokeOthers($user, null, [PasswordResetRequest::STATUS_AWAITING_EMAIL]);
            $token = bin2hex(random_bytes(32));
            $reset = PasswordResetRequest::create([
                'user_id' => $user->id, 'requested_by' => $user->id,
                'status' => PasswordResetRequest::STATUS_AWAITING_EMAIL,
                'request_email' => $email, 'code_hash' => $user->recovery_code_hash,
                'request_contact_number' => $contactNumber,
                'verification_token_hash' => hash('sha256', $token),
                'verification_expires_at' => now()->addMinutes(30),
            ]);
            try {
                Mail::to($email)->send(new RecoveryEmailVerificationMail($user->full_name, route('password.code.verify', $token)));
            } catch (\Throwable $exception) {
                Log::warning('Recovery email verification delivery failed.', ['user_id' => $user->id, 'exception_type' => get_class($exception)]);
                throw ValidationException::withMessages(['delivery' => 'Verification email delivery failed.']);
            }
            $this->audit($user, $reset, 'recovery_email_requested');
        });
    }

    private function requestAdminReset(User $user): void
    {
        if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $this->revokeOthers($user, null);
        $token = bin2hex(random_bytes(32));
        $reset = PasswordResetRequest::create([
            'user_id' => $user->id, 'requested_by' => $user->id,
            'status' => PasswordResetRequest::STATUS_APPROVED, 'is_admin_self_service' => true,
            'request_email' => $user->email, 'code_hash' => $user->recovery_code_hash,
            'code_generated_at' => now(), 'code_generated_by' => $user->id,
            'code_expires_at' => now()->addHours(12), 'reset_token_hash' => hash('sha256', $token),
        ]);
        try {
            Mail::to($user->email)->send(new PasswordResetMail($user->full_name, route('password.code.link', $token), 720));
        } catch (\Throwable $exception) {
            Log::warning('Admin recovery link delivery failed.', ['user_id' => $user->id, 'exception_type' => get_class($exception)]);
            throw ValidationException::withMessages(['delivery' => 'Reset email delivery failed.']);
        }
        $this->audit($user, $reset, 'admin_self_service_recovery_requested');
    }

    private function validAdminSelfService(PasswordResetRequest $reset, User $user): bool
    {
        return $reset->is_admin_self_service && $user->isAdmin() && $user->status === 'active' && ! $user->retired_at
            && $reset->requested_by === $user->id && $reset->approved_by === null
            && $user->email !== null && hash_equals(strtolower($user->email), strtolower($reset->request_email ?? ''))
            && hash_equals($user->recovery_code_hash ?? '', $reset->code_hash ?? '');
    }

    public function verificationForToken(string $token): ?PasswordResetRequest
    {
        return PasswordResetRequest::where('status', PasswordResetRequest::STATUS_AWAITING_EMAIL)
            ->where('verification_token_hash', hash('sha256', $token))
            ->where('verification_expires_at', '>', now())->first();
    }

    public function verify(string $token): bool
    {
        $candidate = $this->verificationForToken($token);
        if (! $candidate) {
            return false;
        }

        return DB::transaction(function () use ($candidate, $token): bool {
            $user = User::with('role')->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $reset = PasswordResetRequest::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($user->status !== 'active' || $user->isSuperAdmin()
                || $reset->status !== PasswordResetRequest::STATUS_AWAITING_EMAIL
                || ! hash_equals($reset->verification_token_hash ?? '', hash('sha256', $token))
                || ! $reset->verification_expires_at?->isFuture()
                || ! hash_equals($user->recovery_code_hash ?? '', $reset->code_hash ?? '')) {
                return false;
            }
            $this->revokeOthers($user, $reset->id);
            $reset->update([
                'status' => PasswordResetRequest::STATUS_PENDING, 'email_verified_at' => now(),
                'verification_token_hash' => null, 'verification_expires_at' => null,
            ]);
            $this->audit($user, $reset, 'recovery_email_verified');

            return true;
        });
    }

    public function verifyIdentity(int $id, User $actor, string $method): void
    {
        $candidate = PasswordResetRequest::findOrFail($id);
        DB::transaction(function () use ($candidate, $actor, $method): void {
            $user = User::with('role')->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $reset = PasswordResetRequest::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->canVerifyResetFor($user), 403);
            if ($reset->status !== PasswordResetRequest::STATUS_PENDING || ! $reset->email_verified_at || ! $reset->request_email
                || ! hash_equals($user->recovery_code_hash ?? '', $reset->code_hash ?? '')) {
                throw ValidationException::withMessages(['request' => 'Verify identity only for a current, email-verified staff request.']);
            }
            if ($reset->hasValidManagerVerification()) {
                return;
            }
            $reset->update([
                'manager_verified_by' => $actor->id, 'manager_verified_at' => now(),
                'manager_verification_method' => $method, 'manager_verified_branch_id' => $user->branch_id,
                'manager_verified_role_id' => $user->role_id, 'manager_verified_email_hash' => hash('sha256', $reset->request_email),
            ]);
            $this->audit($actor, $reset, 'recovery_identity_verified_by_manager');
        }, 3);
    }

    public function approve(int $id, User $actor): bool
    {
        $candidate = PasswordResetRequest::findOrFail($id);

        return DB::transaction(function () use ($candidate, $actor): bool {
            $user = User::with('role')->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $reset = PasswordResetRequest::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->canApproveResetFor($user), 403);
            if (($user->isStaffRole() || $reset->manager_verified_by) && ! $reset->hasValidManagerVerification()) {
                throw ValidationException::withMessages(['request' => 'Staff recovery requires valid identity verification by the same-branch manager before admin approval.']);
            }
            if ($reset->status === PasswordResetRequest::STATUS_APPROVED && $reset->reset_token_hash && $reset->code_expires_at?->isFuture()) {
                return false;
            }
            if ($reset->status !== PasswordResetRequest::STATUS_PENDING || ! $reset->email_verified_at || ! $reset->request_email
                || ! hash_equals($user->recovery_code_hash ?? '', $reset->code_hash ?? '')) {
                throw ValidationException::withMessages(['request' => 'Approve only a current, email-verified request. Ask the user to submit a new request if necessary.']);
            }
            $token = bin2hex(random_bytes(32));
            $reset->update([
                'status' => PasswordResetRequest::STATUS_APPROVED, 'approved_by' => $actor->id,
                'approved_at' => now(), 'code_generated_at' => now(), 'code_generated_by' => $actor->id,
                'identity_verified_at' => now(),
                'code_expires_at' => now()->addHours(12), 'reset_token_hash' => hash('sha256', $token),
            ]);
            try {
                Mail::to($reset->request_email)->send(new PasswordResetMail($user->full_name, route('password.code.link', $token), 720));
            } catch (\Throwable $exception) {
                Log::warning('Approved recovery link delivery failed.', ['request_id' => $reset->id, 'exception_type' => get_class($exception)]);
                throw ValidationException::withMessages(['delivery' => 'The reset email could not be sent. The request remains pending; please retry approval.']);
            }
            $this->audit($actor, $reset, 'recovery_link_approved');

            return true;
        });
    }

    public function resetForToken(string $token): ?PasswordResetRequest
    {
        $reset = PasswordResetRequest::with('user.role')->where('status', PasswordResetRequest::STATUS_APPROVED)
            ->where('reset_token_hash', hash('sha256', $token))->where('code_expires_at', '>', now())->first();
        if ($reset?->is_admin_self_service) {
            return $reset->user && $this->validAdminSelfService($reset, $reset->user) ? $reset : null;
        }

        return $reset?->email_verified_at && $reset->identity_verified_at ? $reset : null;
    }

    public function complete(string $token, string $password): bool
    {
        $candidate = $this->resetForToken($token);
        if (! $candidate) {
            return false;
        }

        return DB::transaction(function () use ($candidate, $token, $password): bool {
            $user = User::with('role')->whereKey($candidate->user_id)->lockForUpdate()->firstOrFail();
            $reset = PasswordResetRequest::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $approver = User::with('role')->find($reset->approved_by);
            $authorized = $reset->is_admin_self_service
                ? $this->validAdminSelfService($reset, $user)
                : $approver?->canApproveResetFor($user) && $reset->email_verified_at && $reset->identity_verified_at;
            if (! $authorized || $user->isSuperAdmin() || $user->status !== 'active' || $user->retired_at
                || (($user->isStaffRole() || $reset->manager_verified_by) && ! $reset->hasValidManagerVerification())
                || $reset->status !== PasswordResetRequest::STATUS_APPROVED
                || ! $reset->code_expires_at?->isFuture()
                || ! hash_equals($reset->reset_token_hash ?? '', hash('sha256', $token))
                || ! hash_equals($user->recovery_code_hash ?? '', $reset->code_hash ?? '')) {
                return false;
            }
            $user->update([
                'password_hash' => Hash::make($password), 'reset_token' => null, 'reset_token_expires' => null,
                'failed_attempts' => 0, 'locked_until' => null,
                'must_change_password' => false, 'temporary_password_expires_at' => null,
            ]);
            $reset->update([
                'status' => PasswordResetRequest::STATUS_USED, 'used_at' => now(),
                'email_verified_at' => $reset->email_verified_at ?? now(),
                'reset_token_hash' => null, 'code_hash' => null, 'code_expires_at' => null,
            ]);
            $this->codes->rotate($user);
            AccountRequest::where('user_id', $user->id)->update(['temporary_password' => null]);
            $this->revokeOthers($user, $reset->id);
            RememberToken::where('user_id', $user->id)->delete();
            $this->audit($user, $reset, 'password_reset_email_link_used');

            return true;
        });
    }

    /** @param array<int, string>|null $states */
    private function revokeOthers(User $user, ?int $except, ?array $states = null): void
    {
        PasswordResetRequest::where('user_id', $user->id)->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->whereIn('status', $states ?? [PasswordResetRequest::STATUS_AWAITING_EMAIL, PasswordResetRequest::STATUS_PENDING, PasswordResetRequest::STATUS_APPROVED])
            ->update([
                'status' => PasswordResetRequest::STATUS_REVOKED, 'revoked_at' => now(),
                'verification_token_hash' => null, 'reset_token_hash' => null, 'code_hash' => null, 'code_expires_at' => null,
            ]);
    }

    private function audit(User $actor, PasswordResetRequest $reset, string $action): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'action' => $action, 'table_name' => 'password_reset_requests',
            'record_id' => $reset->id, 'ip_address' => request()->ip(),
        ]);
    }
}
