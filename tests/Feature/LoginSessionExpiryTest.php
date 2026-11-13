<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class LoginSessionExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ValidateCsrfToken::class, function ($app): ValidateCsrfToken {
            return new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            };
        });
    }

    public function test_stale_login_token_returns_to_a_fresh_login_without_replaying_credentials(): void
    {
        $this->withSession(['_token' => 'current-token'])->post(route('login.submit'), [
            '_token' => 'expired-token', 'login' => 'admin', 'password' => 'Private-Password1!',
        ])->assertRedirect(route('login'))->assertSessionHas('warning')->assertSessionMissing('_old_input.password');
        $this->assertGuest();
        $this->get(route('login'))->assertOk()->assertSee('Your sign-in session expired.')->assertDontSee('Private-Password1!');
    }

    public function test_json_login_keeps_the_419_response(): void
    {
        $this->postJson(route('login.submit'), ['login' => 'admin', 'password' => 'Private-Password1!'])->assertStatus(419);
        $this->assertGuest();
    }

    public function test_other_forms_keep_csrf_protection(): void
    {
        $this->post(route('password.email'), ['email' => 'user@example.test'])->assertStatus(419);
    }

    public function test_valid_csrf_token_reaches_normal_login_validation(): void
    {
        $this->withSession(['_token' => 'valid-token'])->from(route('login'))->post(route('login.submit'), [
            '_token' => 'valid-token', 'login' => 'admin',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('password')->assertSessionMissing('warning');
    }
}
