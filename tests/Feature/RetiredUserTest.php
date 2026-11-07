<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetiredUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_owner_is_hidden_and_cannot_sign_in_be_reactivated_or_keep_using_session(): void
    {
        $branch = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $people = [];
        foreach (['admin', 'owner'] as $name) {
            $people[$name] = User::create(['full_name' => $name === 'owner' ? 'System Owner' : 'Admin', 'username' => $name,
                'role_id' => Role::firstOrCreate(['role_name' => $name])->id, 'branch_id' => $branch->id,
                'password_hash' => bcrypt('password123'), 'status' => 'active']);
        }
        $owner = $people['owner'];
        $owner->retired_at = now();
        $owner->save();
        $audit = AuditLog::create(['user_id' => $owner->id, 'action' => 'historical action', 'table_name' => 'users', 'record_id' => $owner->id]);
        $this->actingAs($people['admin'])->get('/users')->assertOk()->assertDontSee('System Owner');
        $this->postJson('/users/api', ['action' => 'toggle', 'id' => $owner->id, 'status' => 'active'])->assertForbidden();
        $this->actingAs($owner)->get('/profile')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.submit'), ['login' => 'owner', 'password' => 'password123'])->assertRedirect();
        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', ['id' => $audit->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'full_name' => 'System Owner']);

    }
}
