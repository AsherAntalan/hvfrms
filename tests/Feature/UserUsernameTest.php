<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class UserUsernameTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, mixed> */
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $role = Role::firstOrCreate(['role_name' => 'admin'], ['description' => 'Admin']);
        $staffRole = Role::firstOrCreate(['role_name' => 'branch_staff'], ['description' => 'Staff']);
        $this->admin = User::create([
            'full_name' => 'Account Admin', 'username' => 'account.admin', 'email' => 'admin@example.test',
            'password_hash' => Hash::make('password123'), 'role_id' => $role->id, 'branch_id' => $branch->id, 'status' => 'active',
        ]);
        $this->payload = [
            'action' => 'save', 'full_name' => 'New Staff', 'username' => 'new.staff',
            'email' => 'staff@example.test', 'password' => 'password123',
            'role_id' => $staffRole->id, 'branch_id' => $branch->id,
        ];
    }

    #[TestWith(['new.staff'])]
    #[TestWith(['staff@example.test'])]
    public function test_created_username_is_shown_and_can_be_used_to_sign_in(string $login): void
    {
        $this->actingAs($this->admin)->postJson(route('users.api'), $this->payload)->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('users', ['username' => 'new.staff', 'email' => 'staff@example.test']);
        $this->get(route('users'))->assertOk()->assertSee('Username')->assertSee('new.staff');
        $this->post(route('logout'));
        $this->post(route('login.submit'), ['login' => $login, 'password' => 'password123'])->assertRedirect(route('qr'));
        $this->assertAuthenticatedAs(User::where('username', 'new.staff')->firstOrFail());
    }

    public function test_username_can_be_kept_or_changed_without_changing_password(): void
    {
        $payload = array_merge($this->payload, [
            'id' => $this->admin->id, 'username' => $this->admin->username,
            'email' => $this->admin->email, 'role_id' => $this->admin->role_id, 'password' => '',
        ]);
        $passwordHash = $this->admin->password_hash;
        $this->actingAs($this->admin)->postJson(route('users.api'), $payload)->assertOk()->assertJson(['ok' => true]);
        $payload['username'] = 'renamed.admin';
        $this->postJson(route('users.api'), $payload)->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('renamed.admin', $this->admin->fresh()->username);
        $this->assertSame($passwordHash, $this->admin->fresh()->password_hash);
    }

    public function test_invalid_and_duplicate_usernames_are_rejected_without_creating_accounts(): void
    {
        foreach (['', '   ', 'invalid name', 'email@example.test', str_repeat('a', 51), ['array'], 'ACCOUNT.ADMIN'] as $username) {
            $this->actingAs($this->admin)->postJson(route('users.api'), array_merge($this->payload, ['username' => $username]))
                ->assertUnprocessable()->assertJson(['ok' => false]);
        }
        $this->assertSame(1, User::count());
    }
}
