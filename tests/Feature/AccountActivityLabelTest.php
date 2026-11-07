<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountActivityLabelTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $username, string $role = 'branch_staff'): User
    {
        return User::create(['full_name' => $username, 'username' => $username, 'password_hash' => bcrypt('password123'),
            'role_id' => Role::firstOrCreate(['role_name' => $role])->id,
            'branch_id' => Branch::firstOrCreate(['branch_name' => 'Tanza'], ['branch_type' => 'main', 'is_active' => true])->id,
            'status' => 'active']);
    }

    public function test_label_appears_after_thirty_days_without_disabling_account(): void
    {
        $user = $this->person('staff');
        $this->assertFalse($user->hasNoRecentActivity());
        $this->travelTo($user->activity_tracking_started_at->copy()->addDays(30)->subSecond());
        $this->assertFalse($user->hasNoRecentActivity());
        $this->travel(1)->seconds();
        $this->assertTrue($user->hasNoRecentActivity());
        $this->assertSame('active', $user->fresh()->status);
        $admin = $this->person('admin', 'admin');
        $this->actingAs($admin)->get('/users')->assertOk()->assertSee('No activity for 30 days');
        $manager = $this->person('manager', 'branch_manager');
        $this->actingAs($manager)->get('/users')->assertOk()->assertSee('No activity for 30 days');
        auth()->logout();
        $this->post(route('login.submit'), ['login' => $user->username, 'password' => 'password123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->fresh()->hasNoRecentActivity());
    }

    public function test_polling_does_not_clear_label_but_authenticated_page_activity_does(): void
    {
        $user = $this->person('admin', 'admin');
        $user->last_activity_at = now()->subDays(31);
        $user->save();
        $lastUpdate = $user->updated_at->copy();
        $this->actingAs($user)->getJson('/notifications')->assertOk();
        $this->assertTrue($user->fresh()->hasNoRecentActivity());
        $this->get('/profile')->assertOk();
        $this->assertFalse($user->fresh()->hasNoRecentActivity());
        $this->assertTrue($user->fresh()->updated_at->equalTo($lastUpdate));
        $this->assertSame('active', $user->fresh()->status);
    }
}
