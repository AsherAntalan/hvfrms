<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class StaffLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $roleName): User
    {
        $branch = Branch::create(['branch_name' => 'Test location', 'branch_type' => $roleName === 'commissary_staff' ? 'commissary' : 'franchise', 'is_active' => true]);
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::create(['full_name' => 'Test user', 'email' => $roleName.'@redirect.test', 'username' => $roleName,
            'password_hash' => Hash::make('password'), 'role_id' => $role->id, 'branch_id' => $branch->id, 'status' => 'active']);
    }

    #[TestWith(['branch_staff'])]
    #[TestWith(['commissary_staff'])]
    public function test_staff_login_lands_on_scanner_instead_of_remembered_management_url(string $roleName): void
    {
        // Branch Staff land on the QR scanner; Commissary Staff on the Stock
        // Management (Deliver Product) scanner — the Commissary Manager's page.
        $scanner = $roleName === 'commissary_staff' ? 'stock' : 'qr';
        $user = $this->user($roleName);
        $this->get('/orders')->assertRedirect(route('login'))->assertSessionHas('url.intended', route('orders'));
        $this->post('/login', ['login' => $user->username, 'password' => 'password'])
            ->assertRedirect(route($scanner))->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
        $this->get(route($scanner))->assertOk();
        if ($roleName === 'branch_staff') {
            $this->get('/orders')->assertForbidden();
        }
    }

    #[TestWith(['branch_staff', '/login'])]
    #[TestWith(['commissary_staff', '/login'])]
    #[TestWith(['branch_staff', '/'])]
    #[TestWith(['commissary_staff', '/'])]
    public function test_authenticated_staff_use_scanner_landing_for_login_and_root(string $roleName, string $path): void
    {
        $scanner = $roleName === 'commissary_staff' ? 'stock' : 'qr';
        $this->actingAs($this->user($roleName))->get($path)->assertRedirect(route($scanner));
    }

    public function test_manager_keeps_the_permitted_intended_order_url(): void
    {
        $user = $this->user('branch_manager');
        $this->get('/orders')->assertRedirect(route('login'));
        $this->post('/login', ['login' => $user->username, 'password' => 'password'])->assertRedirect(route('orders'));
        $this->assertAuthenticatedAs($user);
        $this->get('/orders')->assertOk();
    }

    public function test_invalid_staff_credentials_do_not_authenticate_or_discard_the_pending_url(): void
    {
        $user = $this->user('branch_staff');
        $this->withSession(['url.intended' => route('orders')])->from('/login')
            ->post('/login', ['login' => $user->username, 'password' => 'wrong'])
            ->assertRedirect(route('login'))->assertSessionHas('error')->assertSessionHas('url.intended', route('orders'));
        $this->assertGuest();
    }
}
