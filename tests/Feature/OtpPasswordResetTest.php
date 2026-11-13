<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OtpPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'branch_manager', string $status = 'active'): User
    {
        $branch = Branch::firstOrCreate(['branch_name' => 'Tanza'], ['branch_type' => 'main', 'is_active' => true]);
        $roleId = Role::firstOrCreate(['role_name' => $role], ['description' => $role])->id;

        return User::create([
            'full_name' => 'Staff User', 'email' => $role.'@yopmail.com', 'username' => $role.'user',
            'password_hash' => Hash::make('oldpassword'), 'role_id' => $roleId,
            'branch_id' => $branch->id, 'status' => $status,
        ]);
    }

    /** Store a known OTP so the verify step can be exercised deterministically. */
    private function primeOtp(User $user, string $code = '123456', $expires = null, int $attempts = 0): void
    {
        $user->update([
            'otp_code_hash' => Hash::make($code),
            'otp_expires_at' => $expires ?? now()->addMinutes(10),
            'otp_attempts' => $attempts,
        ]);
    }

    public function test_valid_otp_sets_the_new_password_and_clears_the_code(): void
    {
        $user = $this->user();
        $this->primeOtp($user, '123456');

        $this->withSession(['otp_email' => $user->email])
            ->post(route('password.otp.update'), ['code' => '123456', 'password' => 'Newpassword1!', 'confirm_password' => 'Newpassword1!'])
            ->assertRedirect(route('login'))->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('Newpassword1!', $fresh->password_hash));
        $this->assertNull($fresh->otp_code_hash);
        $this->assertNull($fresh->otp_expires_at);
        $this->assertDatabaseHas('audit_log', ['user_id' => $user->id, 'action' => 'password_reset_otp']);
    }

    public function test_wrong_code_is_rejected_and_counts_an_attempt(): void
    {
        $user = $this->user();
        $this->primeOtp($user, '123456');

        $this->withSession(['otp_email' => $user->email])
            ->post(route('password.otp.update'), ['code' => '000000', 'password' => 'Newpassword1!', 'confirm_password' => 'Newpassword1!'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, $user->fresh()->otp_attempts);
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password_hash));
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = $this->user();
        $this->primeOtp($user, '123456', now()->subMinute());

        $this->withSession(['otp_email' => $user->email])
            ->post(route('password.otp.update'), ['code' => '123456', 'password' => 'Newpassword1!', 'confirm_password' => 'Newpassword1!'])
            ->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password_hash));
    }

    public function test_code_is_locked_after_five_attempts(): void
    {
        $user = $this->user();
        $this->primeOtp($user, '123456', null, 5);

        $this->withSession(['otp_email' => $user->email])
            ->post(route('password.otp.update'), ['code' => '123456', 'password' => 'Newpassword1!', 'confirm_password' => 'Newpassword1!'])
            ->assertSessionHasErrors('code');
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password_hash));
    }

    public function test_admin_also_uses_the_otp_flow(): void
    {
        $admin = $this->user('admin');
        $this->primeOtp($admin, '123456');

        $this->withSession(['otp_email' => $admin->email])
            ->post(route('password.otp.update'), ['code' => '123456', 'password' => 'Newpassword1!', 'confirm_password' => 'Newpassword1!'])
            ->assertRedirect(route('login'))->assertSessionHas('success');
        $this->assertTrue(Hash::check('Newpassword1!', $admin->fresh()->password_hash));
    }

    public function test_ajax_email_request_returns_otp_mode_for_non_admin(): void
    {
        $user = $this->user();
        $this->postJson(route('password.email'), ['email' => $user->email])
            ->assertOk()->assertJson(['ok' => true, 'mode' => 'otp', 'email' => $user->email]);
        $this->assertNotNull($user->fresh()->otp_code_hash);
    }

    public function test_ajax_verify_returns_json_ok_and_sets_password(): void
    {
        $user = $this->user();
        $this->primeOtp($user, '123456');

        $this->withSession(['otp_email' => $user->email])
            ->postJson(route('password.otp.update'), ['code' => '123456', 'password' => 'Newpassword1!', 'confirm_password' => 'Newpassword1!'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue(Hash::check('Newpassword1!', $user->fresh()->password_hash));
    }

    public function test_ajax_wrong_code_returns_422_json(): void
    {
        $user = $this->user();
        $this->primeOtp($user, '123456');

        $this->withSession(['otp_email' => $user->email])
            ->postJson(route('password.otp.update'), ['code' => '000000', 'password' => 'Newpassword1!', 'confirm_password' => 'Newpassword1!'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_check_otp_validates_code_without_changing_password(): void
    {
        $user = $this->user();
        $this->primeOtp($user, '123456');

        // Valid code → ok, password untouched.
        $this->withSession(['otp_email' => $user->email])
            ->postJson(route('password.otp.check'), ['code' => '123456'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue(Hash::check('oldpassword', $user->fresh()->password_hash));

        // Wrong code → 422 + attempt counted.
        $this->withSession(['otp_email' => $user->email])
            ->postJson(route('password.otp.check'), ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame(1, $user->fresh()->otp_attempts);
    }
}
