<?php

namespace Tests\Feature;

use App\Mail\AlertDigest;
use App\Mail\PasswordResetMail;
use App\Mail\RecoveryEmailVerificationMail;
use App\Models\Alert;
use App\Models\Branch;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminSelfServiceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();

        return User::create(['full_name' => 'Admin', 'username' => 'administrator', 'email' => 'registered@example.test',
            'role_id' => Role::firstOrCreate(['role_name' => 'admin'])->id,
            'branch_id' => Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true])->id,
            'password_hash' => Hash::make('original-password'), 'status' => 'active']);
    }

    private function requestLink(User $user, array $extra = []): string
    {
        $this->post('/account-recovery', array_merge(['username' => $user->username, 'code' => $user->recovery_code, 'email' => $user->email], $extra))
            ->assertRedirect(route('password.code.request'))->assertSessionHas('recovery_requested');
        $mail = Mail::sent(PasswordResetMail::class)->last();

        return basename(parse_url($mail->resetUrl, PHP_URL_PATH));
    }

    public function test_admin_self_service_fires_only_when_submitted_email_matches_registered(): void
    {
        $user = $this->admin();
        $code = $user->recovery_code;

        // A non-matching personal email triggers nothing — no link, no request.
        $this->post('/account-recovery', ['username' => $user->username, 'code' => $code, 'email' => 'attacker@example.test'])
            ->assertSessionHas('recovery_requested');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_requests', 0);

        // The registered email auto-sends the self-service link, only to that address.
        $token = $this->requestLink($user);
        Mail::assertSent(PasswordResetMail::class, fn ($mail) => $mail->hasTo('registered@example.test') && $mail->expiresMinutes === 720);
        Mail::assertNotSent(RecoveryEmailVerificationMail::class);
        $reset = PasswordResetRequest::firstOrFail();
        $this->assertTrue($reset->is_admin_self_service);
        $this->assertNull($reset->approved_by);
        $this->assertNull($reset->email_verified_at);
        $this->assertNotSame($token, $reset->reset_token_hash);
        $this->assertSame(now()->addHours(12)->timestamp, $reset->code_expires_at->timestamp);
        $this->get(route('password.code.link', $token))->assertOk()->assertViewHas('valid', true);
        $payload = ['token' => $token, 'password' => 'new-password123', 'confirm_password' => 'new-password123'];
        $this->post(route('password.code.complete'), $payload)->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password_hash));
        $this->assertNotSame($code, $user->fresh()->recovery_code);
        $this->assertSame('used', $reset->fresh()->status);
        $this->post(route('password.code.complete'), $payload)->assertSessionHasErrors('token');
    }

    #[TestWith(['email'])]
    #[TestWith(['role'])]
    #[TestWith(['disabled'])]
    #[TestWith(['expired'])]
    public function test_link_is_invalidated_by_email_role_status_changes_and_expiry(string $change): void
    {
        $user = $this->admin();
        $token = $this->requestLink($user);
        match ($change) {
            'email' => $user->update(['email' => 'new@example.test']),
            'role' => $user->update(['role_id' => Role::firstOrCreate(['role_name' => 'branch_manager'])->id]),
            'disabled' => $user->update(['status' => 'disabled']),
            'expired' => $this->travel(12)->hours(),
        };
        $this->get(route('password.code.link', $token))->assertOk()->assertViewHas('valid', false);
        $this->post(route('password.code.complete'), ['token' => $token, 'password' => 'new-password123', 'confirm_password' => 'new-password123'])
            ->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password_hash));
    }

    public function test_invalid_codes_do_not_send_mail_and_new_request_invalidates_previous_link(): void
    {
        $user = $this->admin();
        $this->post('/account-recovery', ['username' => $user->username, 'code' => 'ABCD-EFGH-JKMN', 'email' => $user->email])
            ->assertSessionHas('recovery_requested');
        Mail::assertNothingSent();
        $oldToken = $this->requestLink($user);
        $newToken = $this->requestLink($user);
        $this->get(route('password.code.link', $oldToken))->assertOk()->assertViewHas('valid', false);
        $this->get(route('password.code.link', $newToken))->assertOk()->assertViewHas('valid', true);
    }

    public function test_existing_alert_emails_follow_admins_current_registered_email(): void
    {
        $user = $this->admin();
        $user->update(['email' => 'changed@example.test']);
        $alert = Alert::create(['alert_type' => 'operational', 'severity' => 'warning', 'message' => 'Review branch activity',
            'branch_id' => $user->branch_id, 'is_resolved' => false]);
        $this->mock(AlertService::class, function ($mock) use ($alert): void {
            $mock->shouldReceive('generate')->once()->andReturn(collect([$alert]));
        });
        $this->artisan('alerts:generate')->assertSuccessful();
        Mail::assertSent(AlertDigest::class, fn ($mail) => $mail->hasTo('changed@example.test'));
        Mail::assertNotSent(AlertDigest::class, fn ($mail) => $mail->hasTo('registered@example.test'));
    }

    public function test_non_admin_goes_through_email_verification_not_self_service(): void
    {
        $user = $this->admin();
        $user->update(['role_id' => Role::firstOrCreate(['role_name' => 'branch_manager'])->id]);
        $this->post('/account-recovery', ['username' => $user->username, 'code' => $user->recovery_code, 'email' => 'personal@example.test'])
            ->assertSessionHas('recovery_requested');
        Mail::assertSent(RecoveryEmailVerificationMail::class);
        Mail::assertNotSent(PasswordResetMail::class);
        $this->assertSame('awaiting_email', PasswordResetRequest::firstOrFail()->status);
    }
}
