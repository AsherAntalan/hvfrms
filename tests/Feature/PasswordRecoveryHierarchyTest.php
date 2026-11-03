<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Mail\RecoveryEmailVerificationMail;
use App\Models\Branch;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailRecoveryService;
use App\Services\ResetCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Hierarchical, username-based password recovery: manager-requested staff resets
 * with admin approval, direct manager/admin resets, one-time codes, and the
 * public code-reset screen.
 */
class PasswordRecoveryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic flows without IP throttling noise across repeated posts.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->w = $this->world();
        Mail::fake();
    }

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
    }

    private function branch(string $name, string $type = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $name, 'branch_type' => $type, 'is_active' => 1]);
    }

    private function user(string $role, Branch $branch, string $username, ?string $email = null): User
    {
        return User::create([
            'full_name' => ucfirst($username),
            'username' => $username,
            'email' => $email,
            'password_hash' => Hash::make('original-pass-1'),
            'role_id' => $this->role($role)->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }

    private function world(): array
    {
        $commissary = $this->branch('Commissary', 'commissary');
        $baguio = $this->branch('Baguio');
        $silang = $this->branch('Silang');

        return [
            'baguio' => $baguio,
            'silang' => $silang,
            'commissary' => $commissary,
            'super' => $this->user('super_admin', $commissary, 'super', 'super@hv.test'),
            'owner' => $this->user('owner', $commissary, 'owner'),
            'admin' => $this->user('admin', $commissary, 'admin'),
            'bm' => $this->user('branch_manager', $baguio, 'bmbaguio'),
            'bs' => $this->user('branch_staff', $baguio, 'bsbaguio'),
            'bs2' => $this->user('branch_staff', $silang, 'bssilang'),
            'cm' => $this->user('commissary_manager', $commissary, 'cmmanager'),
            'cs' => $this->user('commissary_staff', $commissary, 'csstaff'),
        ];
    }

    private function api(User $actor, array $payload)
    {
        return $this->actingAs($actor)->postJson('/reset-requests/api', $payload);
    }

    /** @return array{PasswordResetRequest, string} */
    private function issueRequest(User $user, string $email = 'personal@hv.test', ?string $contactNumber = null): array
    {
        $this->app['auth']->logout();
        $this->post('/account-recovery', ['username' => $user->username, 'code' => $user->fresh()->recovery_code, 'email' => $email, 'contact_number' => $contactNumber])
            ->assertRedirect(route('password.code.request'))->assertSessionHas('recovery_requested');
        $reset = PasswordResetRequest::where('user_id', $user->id)->latest('id')->firstOrFail();
        $mail = Mail::sent(RecoveryEmailVerificationMail::class)->last();
        $token = basename(parse_url($mail->verificationUrl, PHP_URL_PATH));

        return [$reset, $token];
    }

    private function verifyRequest(string $token): void
    {
        $this->app['auth']->logout();
        $this->post(route('password.code.verify-confirm', $token))->assertRedirect(route('password.code.request'))->assertSessionHas('email_verified');
    }

    private function approveRequest(PasswordResetRequest $reset, User $actor): string
    {
        if ($reset->fresh()->user->isStaffRole() && ! $reset->fresh()->hasValidManagerVerification()) {
            $manager = $reset->user->isBranchStaff() ? $this->w['bm'] : $this->w['cm'];
            $this->api($manager, ['action' => 'verify_identity', 'id' => $reset->id, 'identity_verified' => true, 'verification_method' => 'in_person'])->assertOk();
        }
        if ($actor->isManagerRole()) {
            $actor = $this->w['admin'];
        }
        $this->api($actor, ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertOk();

        return basename(parse_url(Mail::sent(PasswordResetMail::class)->last()->resetUrl, PHP_URL_PATH));
    }

    private function completeRequest(string $token)
    {
        $this->app['auth']->logout();

        return $this->post(route('password.code.complete'), ['token' => $token, 'password' => 'new-password-9', 'confirm_password' => 'new-password-9']);
    }

    public function test_user_verifies_email_manager_verifies_identity_and_admin_approves(): void
    {
        $user = $this->w['bs'];
        $originalCode = $user->recovery_code;
        [$reset, $verification] = $this->issueRequest($user);
        $this->assertSame('awaiting_email', $reset->status);
        $this->assertNull($reset->code_expires_at);
        $this->assertNotSame('personal@hv.test', $reset->getRawOriginal('request_email'));
        $this->assertNotSame($verification, $reset->verification_token_hash);
        $this->get(route('password.code.verify', $verification))->assertOk()->assertSee('Confirm my recovery email')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame('awaiting_email', $reset->fresh()->status);
        $this->verifyRequest($verification);
        $this->get(route('password.code.request'))->assertOk()
            ->assertSee('Request sent for approval')
            ->assertDontSee('Submit reset request')
            ->assertDontSee('Confirm my recovery email');
        $this->assertSame('pending', $reset->fresh()->status);
        $this->actingAs($this->w['bm'])->get(route('reset-requests'))->assertOk()
            ->assertSee('Awaiting manager identity verification')->assertSee('data-reset-action="verify_identity"', false)
            ->assertDontSee('data-reset-action="approve"', false);
        $this->app['auth']->logout();
        $this->travel(45)->minutes();
        $token = $this->approveRequest($reset, $this->w['bm']);
        $reset->refresh();
        $this->assertSame($this->w['admin']->id, $reset->approved_by);
        $this->assertSame($this->w['bm']->id, $reset->manager_verified_by);
        $this->assertNotNull($reset->identity_verified_at);
        $this->assertTrue($reset->code_expires_at->equalTo($reset->approved_at->copy()->addHours(12)));
        $this->assertNotSame($token, $reset->reset_token_hash);
        $this->completeRequest($token)->assertRedirect(route('login'))->assertSessionHas('success');
        $this->assertTrue(Hash::check('new-password-9', $user->fresh()->password_hash));
        $this->assertNotSame($originalCode, $user->fresh()->recovery_code);
        $this->assertNull($user->fresh()->email);
        $this->assertSame('used', $reset->fresh()->status);
        $this->assertNotNull($reset->fresh()->used_at);
        $this->actingAs($this->w['admin'])->get('/reset-requests')->assertOk()
            ->assertSee('Password reset successful')
            ->assertSee('Completed '.$reset->fresh()->used_at->format('M j, Y g:i A'))
            ->assertDontSee('data-reset-action="approve"', false);
        $this->completeRequest($token)->assertSessionHasErrors('token');
        $this->assertGuest();
    }

    public function test_manager_cannot_approve_and_duplicate_admin_approval_does_not_send_again(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs']);
        $this->verifyRequest($verification);
        $this->approveRequest($reset, $this->w['admin']);
        $this->api($this->w['bm'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertForbidden();
        $this->api($this->w['admin'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertOk();
        Mail::assertSent(PasswordResetMail::class, 1);
        $this->assertSame($this->w['admin']->id, $reset->fresh()->approved_by);
    }

    public function test_approval_requires_email_verification_and_in_person_confirmation(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs']);
        $this->api($this->w['admin'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertUnprocessable();
        $this->verifyRequest($verification);
        $this->api($this->w['bm'], ['action' => 'approve', 'id' => $reset->id])->assertUnprocessable();
        $this->api($this->w['bm'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => false])->assertUnprocessable();
        Mail::assertNotSent(PasswordResetMail::class);
        $this->assertSame('pending', $reset->fresh()->status);
    }

    public function test_managers_cannot_approve_other_branches_or_other_role_lines(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs2']);
        $this->verifyRequest($verification);
        foreach (['bm', 'cm'] as $actor) {
            $this->api($this->w[$actor], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertForbidden();
        }
        $this->actingAs($this->w['bm'])->get('/reset-requests')->assertOk()
            ->assertViewHas('requests', fn ($rows) => ! $rows->contains('id', $reset->id));
        $this->actingAs($this->w['bs'])->get('/reset-requests')->assertForbidden();
    }

    public function test_manager_reset_requires_admin_and_admin_reset_requires_super_admin(): void
    {
        [$manager, $verification] = $this->issueRequest($this->w['bm']);
        $this->verifyRequest($verification);
        $this->api($this->w['bm'], ['action' => 'approve', 'id' => $manager->id, 'identity_verified' => true])->assertForbidden();
        $this->api($this->w['cm'], ['action' => 'approve', 'id' => $manager->id, 'identity_verified' => true])->assertForbidden();
        $this->approveRequest($manager, $this->w['admin']);
        [$admin, $verification] = $this->issueRequest($this->w['admin']);
        $this->verifyRequest($verification);
        $this->api($this->w['owner'], ['action' => 'approve', 'id' => $admin->id, 'identity_verified' => true])->assertForbidden();
        $this->approveRequest($admin, $this->w['super']);
    }

    public function test_unknown_invalid_disabled_and_super_admin_requests_have_identical_public_responses(): void
    {
        $this->w['bs']->update(['status' => 'disabled']);
        foreach (['unknown', 'bsbaguio', 'super'] as $username) {
            $this->post('/account-recovery', ['username' => $username, 'code' => 'ABCD-EFGH-JKMN', 'email' => 'personal@hv.test'])
                ->assertRedirect(route('password.code.request'))->assertSessionHas('recovery_requested');
        }
        $this->post('/account-recovery', ['username' => $this->w['bm']->username, 'code' => 'INVALID', 'email' => 'personal@hv.test'])
            ->assertRedirect(route('password.code.request'))->assertSessionHas('recovery_requested');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    public function test_verification_expires_and_cannot_be_replayed(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs']);
        $this->travel(30)->minutes();
        $this->post(route('password.code.verify-confirm', $verification))->assertSessionHas('error');
        $this->assertNull($reset->fresh()->email_verified_at);
        [$replacement, $token] = $this->issueRequest($this->w['bs']);
        $this->verifyRequest($token);
        $this->post(route('password.code.verify-confirm', $token))->assertSessionHas('error');
        $this->assertSame('pending', $replacement->fresh()->status);
    }

    public function test_reset_link_expires_exactly_twelve_hours_after_approval(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs']);
        $this->verifyRequest($verification);
        $token = $this->approveRequest($reset, $this->w['bm']);
        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->expiresMinutes === 720);
        $this->assertStringContainsString('12 hours', Mail::sent(PasswordResetMail::class)->last()->render());
        $expiresAt = $reset->fresh()->code_expires_at;
        $this->travelTo($expiresAt->copy()->subSecond());
        $this->assertNotNull(app(EmailRecoveryService::class)->resetForToken($token));
        $this->travelTo($expiresAt);
        $this->completeRequest($token)->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('original-pass-1', $this->w['bs']->fresh()->password_hash));
    }

    public function test_new_verified_request_supersedes_old_link_and_rejection_blocks_reset(): void
    {
        [$first, $verification] = $this->issueRequest($this->w['bs']);
        $this->verifyRequest($verification);
        $oldToken = $this->approveRequest($first, $this->w['bm']);
        [$second, $verification] = $this->issueRequest($this->w['bs'], 'another@hv.test');
        $this->verifyRequest($verification);
        $this->completeRequest($oldToken)->assertSessionHasErrors('token');
        $token = $this->approveRequest($second, $this->w['admin']);
        $this->api($this->w['admin'], ['action' => 'reject', 'id' => $second->id, 'rejection_reason' => 'cancelled'])->assertOk();
        $this->completeRequest($token)->assertSessionHasErrors('token');
        $this->api($this->w['admin'], ['action' => 'delete', 'id' => $second->id])->assertOk();
    }

    public function test_verification_delivery_failure_is_generic_and_rolls_back_request(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Transport failed with secret details.'));
        $this->post('/account-recovery', ['username' => $this->w['bs']->username, 'code' => $this->w['bs']->recovery_code, 'email' => 'personal@hv.test'])
            ->assertRedirect(route('password.code.request'))->assertSessionHas('recovery_requested');
        $this->assertDatabaseCount('password_reset_requests', 0);
    }

    public function test_approval_delivery_failure_leaves_request_pending_and_allows_retry(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs']);
        $this->verifyRequest($verification);
        $originalMailer = Mail::getFacadeRoot();
        $this->api($this->w['bm'], ['action' => 'verify_identity', 'id' => $reset->id, 'identity_verified' => true, 'verification_method' => 'in_person'])->assertOk();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable.'));
        $this->api($this->w['admin'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertUnprocessable();
        $this->assertSame('pending', $reset->fresh()->status);
        $this->assertNull($reset->fresh()->reset_token_hash);
        Mail::swap($originalMailer);
        $this->approveRequest($reset, $this->w['admin']);
        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_existing_account_codes_alone_cannot_reset_and_legacy_manual_approval_is_retired(): void
    {
        $this->post('/account-recovery', ['username' => $this->w['bs']->username, 'code' => $this->w['bs']->recovery_code,
            'password' => 'new-password-9', 'confirm_password' => 'new-password-9'])->assertSessionHasErrors('email');
        $this->api($this->w['bm'], ['action' => 'request', 'user_id' => $this->w['bs']->id])->assertUnprocessable();
        $this->assertTrue(Hash::check('original-pass-1', $this->w['bs']->fresh()->password_hash));
        $this->app['auth']->logout();
        $this->get('/account-recovery')->assertOk()->assertSee('Personal email for this request')->assertDontSee('name="password"', false);
    }

    public function test_sensitive_fields_are_hidden_and_manager_sees_only_assigned_staff_email_requests(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs'], 'personal@hv.test', '+63 917 123 4567');
        $this->assertSame('+63 917 123 4567', $reset->request_contact_number);
        $this->assertNotSame('+63 917 123 4567', $reset->getRawOriginal('request_contact_number'));
        $this->verifyRequest($verification);
        foreach (['code_hash', 'reset_token_hash', 'verification_token_hash', 'request_email', 'request_contact_number'] as $field) {
            $this->assertArrayNotHasKey($field, $reset->fresh()->toArray());
        }
        $this->actingAs($this->w['bm'])->get('/reset-requests')->assertOk()->assertSee('personal@hv.test')
            ->assertSee('+63 917 123 4567')->assertSee('Unverified · contact only')
            ->assertSee('I verified identity and confirmed')->assertDontSee('View recovery code');
        $this->actingAs($this->w['cm'])->get('/reset-requests')->assertOk()->assertDontSee('+63 917 123 4567');
        $this->actingAs($this->w['admin'])->get('/users')->assertOk()->assertSee($this->w['bs']->recovery_code);
    }

    public function test_request_contact_number_is_optional_and_rejects_invalid_values(): void
    {
        [$reset] = $this->issueRequest($this->w['bs']);
        $this->assertNull($reset->request_contact_number);
        $this->get('/account-recovery')->assertOk()->assertSee('Contact number for this request (optional)');

        $requestCount = PasswordResetRequest::count();
        foreach (['not a number', '<script>alert(1)</script>', str_repeat('1', 31)] as $invalidNumber) {
            $this->post('/account-recovery', [
                'username' => $this->w['bs']->username,
                'code' => $this->w['bs']->fresh()->recovery_code,
                'email' => 'personal@hv.test',
                'contact_number' => $invalidNumber,
            ])->assertSessionHasErrors('contact_number');
        }
        $this->assertSame($requestCount, PasswordResetRequest::count());
    }

    public function test_stale_account_code_or_changed_approver_scope_invalidates_the_link(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs']);
        $this->verifyRequest($verification);
        $token = $this->approveRequest($reset, $this->w['bm']);
        $this->w['bm']->update(['branch_id' => $this->w['silang']->id]);
        $this->completeRequest($token)->assertSessionHasErrors('token');
        $this->w['bm']->update(['branch_id' => $this->w['baguio']->id]);
        app(ResetCodeService::class)->rotate($this->w['bs']);
        $this->completeRequest($token)->assertSessionHasErrors('token');
    }

    public function test_disabled_target_cannot_be_approved_but_request_can_be_rejected(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs']);
        $this->verifyRequest($verification);
        $this->w['bs']->update(['status' => 'disabled']);
        $this->api($this->w['admin'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertForbidden();
        $this->api($this->w['admin'], ['action' => 'reject', 'id' => $reset->id, 'rejection_reason' => 'identity_unverified'])->assertOk();
        Mail::assertNotSent(PasswordResetMail::class);
    }

    public function test_rejection_requires_a_valid_reason_and_invalidates_email_verification(): void
    {
        [$reset, $verification] = $this->issueRequest($this->w['bs']);
        foreach ([null, 'made_up_reason'] as $reason) {
            $this->api($this->w['admin'], ['action' => 'reject', 'id' => $reset->id, 'rejection_reason' => $reason])
                ->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        }
        $this->api($this->w['cm'], ['action' => 'reject', 'id' => $reset->id, 'rejection_reason' => 'duplicate'])->assertForbidden();
        $this->assertSame('awaiting_email', $reset->fresh()->status);
        $this->api($this->w['admin'], ['action' => 'reject', 'id' => $reset->id, 'rejection_reason' => 'not_requested'])->assertOk();
        $this->assertSame('rejected', $reset->refresh()->status);
        $this->assertSame('not_requested', $reset->rejection_reason);
        $this->assertNull($reset->verification_token_hash);
        $this->app['auth']->logout();
        $this->post(route('password.code.verify-confirm', $verification))->assertSessionHas('error');
        $this->actingAs($this->w['bm'])->get('/reset-requests')->assertOk()
            ->assertSee('Account holder did not request this reset')
            ->assertSee('data-reset-action="delete"', false)
            ->assertDontSee('data-reset-action="reject"', false)
            ->assertDontSee('data-reset-action="revoke"', false);
        $this->api($this->w['admin'], ['action' => 'reject', 'id' => $reset->id, 'rejection_reason' => 'duplicate'])->assertUnprocessable();
        $this->assertSame('not_requested', $reset->fresh()->rejection_reason);
        $this->api($this->w['bm'], ['action' => 'revoke', 'id' => $reset->id])->assertStatus(400);
    }

    public function test_staff_requires_manager_verification_before_admin_can_approve(): void
    {
        [$reset, $token] = $this->issueRequest($this->w['bs']);
        $payload = ['action' => 'verify_identity', 'id' => $reset->id, 'identity_verified' => true, 'verification_method' => 'callback'];
        $this->api($this->w['bm'], $payload)->assertUnprocessable();
        $this->verifyRequest($token);
        $this->api($this->w['admin'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertUnprocessable();
        $this->actingAs($this->w['admin'])->get('/reset-requests')->assertOk()
            ->assertSee('Awaiting manager identity verification')->assertDontSee('data-reset-action="approve"', false);
        $this->api($this->w['bm'], array_replace($payload, ['verification_method' => 'unknown']))->assertUnprocessable();
        $this->api($this->w['bm'], array_replace($payload, ['verification_method' => 'video_call']))->assertUnprocessable();
        $this->api($this->w['bm'], array_replace($payload, ['identity_verified' => false]))->assertUnprocessable();
        $this->api($this->w['cm'], $payload)->assertForbidden();
        $this->api($this->w['admin'], $payload)->assertForbidden();
        $this->api($this->w['bm'], $payload)->assertOk();
        $reset->refresh();
        $this->assertTrue($reset->hasValidManagerVerification());
        $this->assertSame('callback', $reset->manager_verification_method);
        $verifiedAt = $reset->manager_verified_at;
        $this->travel(10)->minutes();
        $this->api($this->w['bm'], $payload)->assertOk();
        $this->assertTrue($reset->fresh()->manager_verified_at->equalTo($verifiedAt));
        $this->assertNull($reset->fresh()->code_expires_at);
        Mail::assertNotSent(PasswordResetMail::class);
        $this->actingAs($this->w['admin'])->get('/reset-requests')->assertOk()
            ->assertSee('Identity verified by')->assertSee($this->w['bm']->full_name)
            ->assertSee('Call')->assertSee('data-reset-action="approve"', false);
        $this->api($this->w['bm'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertForbidden();
        $this->api($this->w['bm'], ['action' => 'reject', 'id' => $reset->id, 'rejection_reason' => 'duplicate'])->assertForbidden();
        $this->approveRequest($reset, $this->w['admin']);
        $reset->refresh();
        $this->assertTrue($reset->code_expires_at->equalTo($reset->approved_at->copy()->addHours(12)));
    }

    public function test_changed_manager_scope_or_request_email_requires_fresh_identity_verification(): void
    {
        [$reset, $token] = $this->issueRequest($this->w['bs']);
        $this->verifyRequest($token);
        $payload = ['action' => 'verify_identity', 'id' => $reset->id, 'identity_verified' => true, 'verification_method' => 'callback'];
        $this->api($this->w['bm'], $payload)->assertOk();
        $this->w['bm']->update(['branch_id' => $this->w['silang']->id]);
        $this->api($this->w['admin'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertUnprocessable();
        $this->w['bm']->update(['branch_id' => $this->w['baguio']->id]);
        $reset->update(['request_email' => 'changed@hv.test']);
        $this->api($this->w['admin'], ['action' => 'approve', 'id' => $reset->id, 'identity_verified' => true])->assertUnprocessable();
        $this->assertFalse($reset->fresh()->hasValidManagerVerification());
        Mail::assertNotSent(PasswordResetMail::class);
    }
}
