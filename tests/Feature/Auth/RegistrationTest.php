<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accounts are created by an administrator (Utilisateurs page), never by the public —
 * self-registration was removed so a stranger can't create an account on a live farm system.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_is_not_available(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_public_registration_cannot_create_a_user(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertNull(User::where('email', 'test@example.com')->first());
    }
}
