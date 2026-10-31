<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function branch(): Branch
    {
        return Branch::firstOrCreate(['branch_name' => 'Tanza'], ['branch_type' => 'main', 'is_active' => true]);
    }

    private function user(string $email, string $status = 'active', string $role = 'branch_manager'): User
    {
        $roleId = Role::firstOrCreate(['role_name' => $role], ['description' => $role])->id;

        return User::create([
            'full_name' => 'Test User',
            'email' => $email,
            'username' => str_replace(['@', '.'], '', $email),
            'password_hash' => Hash::make('oldpassword'),
            'role_id' => $roleId,
            'branch_id' => $this->branch()->id,
            'status' => $status,
        ]);
    }

    /** A raw token whose sha256 hash is stored on the user with the given expiry. */
    private function issueToken(User $user, $expires = null): string
    {
        $raw = bin2hex(random_bytes(32));
        $user->update([
            'reset_token' => hash('sha256', $raw),
            'reset_token_expires' => $expires ?? now()->addHour(),
        ]);

        return $raw;
    }

    public function test_login_page_links_to_recovery_and_forgot_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('password.request'), false)
            ->assertSee('Reset your password using your registered email')
            ->assertDontSee('self-service password reset');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Forgot your password?')
            ->assertSee('Send reset link');
    }

    public function test_eligible_active_account_receives_a_reset_email(): void
    {
        Mail::fake();
        $user = $this->user('active@test.com');

        $response = $this->post(route('password.email'), ['email' => 'active@test.com']);

        $response->assertRedirect(route('password.request'))->assertSessionHas('reset_requested', true);
        Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo('active@test.com'));
        $this->assertNotNull($user->fresh()->reset_token);
        $this->assertDatabaseHas('audit_log', ['user_id' => $user->id, 'action' => 'password_reset_request']);
    }

    public function test_unknown_and_inactive_accounts_get_the_same_response_without_mail(): void
    {
        Mail::fake();
        $inactive = $this->user('disabled@test.com', 'disabled');

        $unknown = $this->post(route('password.email'), ['email' => 'nobody@test.com']);
        $unknown->assertRedirect(route('password.request'))->assertSessionHas('reset_requested', true);

        $off = $this->post(route('password.email'), ['email' => 'disabled@test.com']);
        $off->assertRedirect(route('password.request'))->assertSessionHas('reset_requested', true);

        Mail::assertNothingSent();
        $this->assertNull($inactive->fresh()->reset_token);
    }

    public function test_no_raw_link_or_token_is_exposed_in_response_or_session(): void
    {
        Mail::fake();
        $this->user('active@test.com');

        $this->post(route('password.email'), ['email' => 'active@test.com'])
            ->assertSessionMissing('reset_link');

        // The confirmation page never prints a reset URL or raw token.
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Check your email')
            ->assertDontSee('/reset-password/');
    }

    public function test_successful_password_reset(): void
    {
        $user = $this->user('active@test.com');
        $raw = $this->issueToken($user);

        $this->get(route('password.reset', $raw))->assertOk()->assertSee('Set a new password');

        $this->post(route('password.update'), [
            'token' => $raw, 'password' => 'newpassword1', 'confirm_password' => 'newpassword1',
        ])->assertRedirect(route('login'))->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newpassword1', $fresh->password_hash));
        $this->assertNull($fresh->reset_token);
        $this->assertDatabaseHas('audit_log', ['user_id' => $user->id, 'action' => 'password_reset']);
    }

    public function test_invalid_and_expired_tokens_are_rejected(): void
    {
        $user = $this->user('active@test.com');

        // Invalid token.
        $this->get(route('password.reset', 'not-a-real-token'))->assertOk()->assertSee('Link no longer valid');
        $this->post(route('password.update'), ['token' => 'not-a-real-token', 'password' => 'newpassword1', 'confirm_password' => 'newpassword1'])
            ->assertSessionHasErrors('token');

        // Expired token.
        $raw = $this->issueToken($user, now()->subMinute());
        $this->get(route('password.reset', $raw))->assertOk()->assertSee('Link no longer valid');
        $this->post(route('password.update'), ['token' => $raw, 'password' => 'newpassword1', 'confirm_password' => 'newpassword1'])
            ->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password_hash));
    }

    public function test_reused_token_is_rejected(): void
    {
        $user = $this->user('active@test.com');
        $raw = $this->issueToken($user);

        $this->post(route('password.update'), ['token' => $raw, 'password' => 'newpassword1', 'confirm_password' => 'newpassword1'])
            ->assertRedirect(route('login'));

        // Second use of the same raw token after consumption.
        $this->post(route('password.update'), ['token' => $raw, 'password' => 'another12345', 'confirm_password' => 'another12345'])
            ->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('newpassword1', $user->fresh()->password_hash));
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $user = $this->user('active@test.com');
        $raw = $this->issueToken($user);

        $this->post(route('password.update'), ['token' => $raw, 'password' => 'newpassword1', 'confirm_password' => 'different1234'])
            ->assertSessionHasErrors('confirm_password');
        $this->assertNotNull($user->fresh()->reset_token);
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password_hash));
    }

    public function test_short_password_is_rejected(): void
    {
        $user = $this->user('active@test.com');
        $raw = $this->issueToken($user);

        $this->post(route('password.update'), ['token' => $raw, 'password' => 'short', 'confirm_password' => 'short'])
            ->assertSessionHasErrors('password');
    }

    public function test_inactive_account_cannot_reset_with_an_existing_token(): void
    {
        $user = $this->user('active@test.com');
        $raw = $this->issueToken($user);
        $user->update(['status' => 'disabled']);

        $this->get(route('password.reset', $raw))->assertOk()->assertSee('Link no longer valid');
        $this->post(route('password.update'), ['token' => $raw, 'password' => 'newpassword1', 'confirm_password' => 'newpassword1'])
            ->assertSessionHasErrors('token');
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password_hash));
    }

    public function test_admin_reset_invalidates_outstanding_reset_tokens(): void
    {
        $owner = $this->user('owner@test.com', 'active', 'owner');
        $target = $this->user('target@test.com');
        $this->issueToken($target);
        $this->assertNotNull($target->fresh()->reset_token);

        $this->actingAs($owner)->postJson('/users/api', [
            'action' => 'reset_password', 'id' => $target->id, 'new_password' => 'adminset12345',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertNull($target->fresh()->reset_token);
        $this->assertNull($target->fresh()->reset_token_expires);
    }

    public function test_mail_failure_still_returns_the_generic_confirmation(): void
    {
        // Force a transport failure; the controller must swallow it and respond
        // generically without leaking the error, token, or link.
        config(['mail.default' => 'failing']);
        config(['mail.mailers.failing' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $this->user('active@test.com');

        $this->post(route('password.email'), ['email' => 'active@test.com'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('reset_requested', true)
            ->assertSessionMissing('reset_link');
    }

    public function test_requests_are_throttled(): void
    {
        $this->user('active@test.com');
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('password.email'), ['email' => 'active@test.com'])->assertRedirect(route('password.request'));
        }
        // 4th within a minute is throttled (limiter responds with a redirect + error).
        $this->post(route('password.email'), ['email' => 'active@test.com'])->assertSessionHas('error');
    }
}
