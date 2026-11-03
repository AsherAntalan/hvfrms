<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The shared confirm/success modal is present on app pages, delete flows are
 * wired to it (no native confirm), and redirect flash success is shown once
 * through the modal rather than the old dismissible banner.
 */
class SharedModalRenderTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $role = Role::firstOrCreate(['role_name' => 'owner'], ['description' => 'owner']);
        $branch = Branch::create(['branch_name' => 'HQ', 'branch_type' => 'commissary', 'is_active' => 1]);

        return User::create([
            'full_name' => 'Owner', 'username' => 'owner', 'email' => null,
            'password_hash' => Hash::make('secret123'), 'role_id' => $role->id,
            'branch_id' => $branch->id, 'status' => 'active',
        ]);
    }

    public function test_shared_modal_partial_renders_on_app_pages(): void
    {
        $this->actingAs($this->owner())->get('/reset-requests')->assertOk()
            ->assertSee('id="hvModal"', false)
            ->assertSee("typeof window.hvModal.confirm === 'function'", false)
            ->assertDontSee('if (!el || window.hvModal) { return; }', false);
    }

    public function test_reset_request_delete_uses_shared_modal_not_native_confirm(): void
    {
        $this->actingAs($this->owner())->get('/reset-requests')->assertOk()
            ->assertSee('deleteResetRequest', false)
            ->assertSee('hvModal.confirm', false)
            // The delete path no longer calls window.confirm for deletion.
            ->assertDontSee("confirm('Permanently delete", false);
    }

    public function test_redirect_flash_success_renders_once_via_modal_not_banner(): void
    {
        $response = $this->actingAs($this->owner())
            ->withSession(['success' => 'Saved it.'])
            ->get('/reset-requests')->assertOk();

        $response->assertSee('hvModal.success({ title: \'Success\'', false)
            ->assertSee('Saved it.', false)
            // The old dismissible success banner is gone (no double success UI).
            ->assertDontSee('alert-success alert-dismissible', false);
    }
}
