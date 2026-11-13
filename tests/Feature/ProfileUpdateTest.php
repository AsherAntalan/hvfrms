<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $username): User
    {
        return User::create([
            'full_name' => 'Original Name', 'username' => $username, 'email' => $username.'@example.test',
            'role_id' => Role::firstOrCreate(['role_name' => 'admin'])->id,
            'branch_id' => Branch::firstOrCreate(['branch_name' => 'Tanza'], ['branch_type' => 'main', 'is_active' => true])->id,
            'password_hash' => Hash::make('password123'), 'status' => 'active',
        ]);
    }

    public function test_update_is_self_scoped_audited_and_locked_for_exactly_thirty_days(): void
    {
        $user = $this->person('first');
        $other = $this->person('other');
        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('Save Account Information');
        $payload = ['full_name' => ' New Name ', 'email' => ' NEW@example.test ', 'current_password' => 'password123',
            'id' => $other->id, 'role_id' => 999, 'branch_id' => 999, 'profile_updated_at' => '2000-01-01'];
        $this->post(route('profile.information'), $payload)->assertRedirect(route('profile'))->assertSessionHasNoErrors();
        $this->assertSame('New Name', $user->fresh()->full_name);
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertSame('Original Name', $other->fresh()->full_name);
        $this->assertSame($user->role_id, $user->fresh()->role_id);
        $this->assertDatabaseHas('audit_log', ['action' => 'profile information updated', 'user_id' => $user->id]);
        $this->get('/profile')->assertOk()->assertDontSee('Save Account Information')->assertSee('Name can be changed again on')->assertSee('Email can be changed again on');
        $nextUpdate = $user->fresh()->profile_updated_at->copy()->addDays(30);
        $this->travelTo($nextUpdate->copy()->subSecond());
        $this->post(route('profile.information'), array_replace($payload, ['full_name' => 'Too Soon']))
            ->assertSessionHasErrorsIn('profileInformation', 'full_name');
        $this->assertSame('New Name', $user->fresh()->full_name);
        $this->travelTo($nextUpdate);
        $this->post(route('profile.information'), array_replace($payload, ['full_name' => 'Allowed Now']))->assertSessionHasNoErrors();
        $this->assertSame('Allowed Now', $user->fresh()->full_name);
    }

    public function test_name_and_email_have_independent_cooldowns(): void
    {
        $user = $this->person('first');
        $this->actingAs($user);
        $payload = ['full_name' => 'New Name', 'email' => $user->email, 'current_password' => 'password123'];
        $this->post(route('profile.information'), $payload)->assertSessionHasNoErrors();
        $nameChangedAt = $user->fresh()->name_updated_at;
        $this->assertNull($user->fresh()->email_updated_at);
        $this->get('/profile')->assertOk()->assertSee('Save Account Information')->assertSee('Name can be changed again on')->assertDontSee('Email can be changed again on');
        $this->travel(5)->days();
        $payload['email'] = 'new@example.test';
        $this->post(route('profile.information'), $payload)->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->name_updated_at->equalTo($nameChangedAt));
        $emailChangedAt = $user->fresh()->email_updated_at;
        $this->travelTo($nameChangedAt->copy()->addDays(30));
        $payload['full_name'] = 'Next Name';
        $this->post(route('profile.information'), $payload)->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->email_updated_at->equalTo($emailChangedAt));
        $this->post(route('profile.information'), array_replace($payload, ['email' => 'too-soon@example.test']))->assertSessionHasErrorsIn('profileInformation', 'email');
        $this->assertSame('new@example.test', $user->fresh()->email);
    }

    public function test_invalid_requests_and_unchanged_submission_do_not_start_cooldown(): void
    {
        $user = $this->person('first');
        $other = $this->person('other');
        $this->actingAs($user);
        $payload = ['full_name' => 'Original Name', 'email' => $user->email, 'current_password' => 'password123'];
        foreach ([['current_password' => 'wrong'], ['email' => 'invalid'], ['email' => $other->email], ['full_name' => '']] as $invalid) {
            $this->post(route('profile.information'), array_replace($payload, $invalid))->assertSessionHasErrors();
            $this->assertNull($user->fresh()->profile_updated_at);
        }
        $this->post(route('profile.information'), $payload)->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->profile_updated_at);
        $this->assertDatabaseMissing('audit_log', ['action' => 'profile information updated']);
    }

    public function test_password_change_remains_available_and_guest_cannot_update(): void
    {
        $user = $this->person('first');
        $user->profile_updated_at = now();
        $user->save();
        $this->actingAs($user)->post(route('profile.password'), ['current_password' => 'password123',
            'new_password' => 'New-Password123!', 'new_password_confirmation' => 'New-Password123!'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('New-Password123!', $user->fresh()->password_hash));
        auth()->logout();
        $this->post(route('profile.information'), [])->assertRedirect(route('login'));
    }
}
