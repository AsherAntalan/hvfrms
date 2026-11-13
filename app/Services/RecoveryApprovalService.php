<?php

namespace App\Services;

use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Derives the recovery requests an approver may act on, live from request state
 * and the approval hierarchy. Nothing is stored per recipient: a request is
 * actionable only while it is email-verified and still pending, and only for a
 * viewer currently permitted to approve its account holder. Approving, rejecting,
 * superseding, or deleting a request removes it for every approver at once, and a
 * branch or role change re-scopes who sees it on the next read.
 */
class RecoveryApprovalService
{
    /**
     * Pending, email-verified requests the viewer may review or verify.
     *
     * @return Collection<int, PasswordResetRequest>
     */
    public function actionableFor(User $viewer): Collection
    {
        if (! $viewer->isManagerRole() && ! $viewer->canAuthorizeResets()) {
            return collect();
        }

        return PasswordResetRequest::query()
            ->with(['user.role', 'user.branch', 'managerVerifier.role'])
            ->where('status', PasswordResetRequest::STATUS_PENDING)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('request_email')
            ->orderByDesc('email_verified_at')
            ->get()
            ->filter(fn (PasswordResetRequest $reset): bool => $reset->user
                && $reset->user->status === 'active'
                && ($viewer->canApproveResetFor($reset->user)
                    || ($viewer->canVerifyResetFor($reset->user) && ! $reset->hasValidManagerVerification())))
            ->values();
    }

    public function countFor(User $viewer): int
    {
        return $this->actionableFor($viewer)->count();
    }

    /**
     * Bell notification payloads. Each carries only the account holder's name,
     * branch, and request time — never recovery codes, verification/reset tokens,
     * passwords, request emails, contact numbers, or any link that could approve
     * the request. The review link is a read-only GET to the Reset Requests page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function notificationItemsFor(User $viewer): array
    {
        return $this->actionableFor($viewer)->map(fn (PasswordResetRequest $reset): array => [
            'id' => 'recovery-'.$reset->id.($reset->hasValidManagerVerification() ? '-manager-verified' : ''),
            'type' => 'recovery',
            'category' => 'recovery',
            'severity' => 'warning',
            'message' => ($viewer->canVerifyResetFor($reset->user) ? 'Password reset identity verification needed — ' : ($reset->user->isStaffRole() && ! $reset->hasValidManagerVerification() ? 'Password reset awaiting manager verification — ' : 'Password reset approval needed — ')).($reset->user->full_name ?? 'account holder'),
            'context' => $reset->user->branch->branch_name ?? 'No branch',
            'time' => ($reset->email_verified_at ?? $reset->updated_at)->diffForHumans(),
            'ts' => ($reset->email_verified_at ?? $reset->updated_at)->timestamp,
            'link' => route('reset-requests', ['request' => $reset->id]),
        ])->all();
    }
}
