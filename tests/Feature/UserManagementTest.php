<?php

namespace Tests\Feature;

use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Account administration (super_admin only): create/edit keep a user's division inside their
 * farm and never pin a farm_manager to one division; a password reset ends the user's sessions
 * and scan-station tokens; and no super_admin account can be edited, reset or deleted — not by
 * another admin, and not by itself from the profile page.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Farm $farmA;

    private Farm $farmB;

    private Enterprise $divisionA;

    private Enterprise $divisionB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'super_admin', 'farm_id' => null]);
        $this->farmA = Farm::create(['name' => 'Farm A']);
        $this->farmB = Farm::create(['name' => 'Farm B']);
        $this->divisionA = Enterprise::create(['farm_id' => $this->farmA->id, 'name' => 'Div A', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
        $this->divisionB = Enterprise::create(['farm_id' => $this->farmB->id, 'name' => 'Div B', 'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44]);
    }

    private function account(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pointeur', 'email' => 'pointeur@example.test', 'password' => 'secret-123',
            'role' => 'data_entry', 'farm_id' => $this->farmA->id, 'enterprise_id' => $this->divisionA->id,
            'acces_pointage' => 'saisie', 'acces_stock' => 'aucun',
        ], $overrides);
    }

    // --- store() ---

    public function test_store_rejects_a_division_of_another_farm(): void
    {
        $this->actingAs($this->admin)->post('/users', $this->account(['enterprise_id' => $this->divisionB->id]))
            ->assertSessionHasErrors('enterprise_id');

        $this->assertDatabaseMissing('users', ['email' => 'pointeur@example.test']);
    }

    public function test_store_works_without_any_division_field(): void
    {
        $data = $this->account();
        unset($data['enterprise_id']);

        $this->actingAs($this->admin)->post('/users', $data)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'pointeur@example.test', 'enterprise_id' => null]);
    }

    public function test_store_never_pins_a_farm_manager_to_a_division(): void
    {
        $this->actingAs($this->admin)->post('/users', $this->account(['role' => 'farm_manager']))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'pointeur@example.test', 'role' => 'farm_manager', 'enterprise_id' => null]);
    }

    // --- update() ---

    public function test_admin_can_edit_a_user(): void
    {
        $user = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farmA->id, 'acces_pointage' => 'saisie']);

        $this->actingAs($this->admin)->put("/users/{$user->id}", $this->account([
            'name' => 'Magasinier', 'email' => 'mag@example.test', 'enterprise_id' => null,
            'acces_pointage' => 'aucun', 'acces_stock' => 'saisie',
        ]))->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Magasinier', $user->name);
        $this->assertSame('mag@example.test', $user->email);
        $this->assertSame(['aucun', 'saisie'], [$user->acces_pointage, $user->acces_stock]);
    }

    public function test_edit_keeps_email_unique_but_allows_the_users_own(): void
    {
        $user = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farmA->id, 'email' => 'mine@example.test']);
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($this->admin)->put("/users/{$user->id}", $this->account(['email' => 'taken@example.test']))
            ->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->put("/users/{$user->id}", $this->account(['email' => 'mine@example.test']))
            ->assertSessionHasNoErrors();
    }

    public function test_edit_rejects_a_division_of_another_farm(): void
    {
        $user = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farmA->id]);

        $this->actingAs($this->admin)->put("/users/{$user->id}", $this->account(['enterprise_id' => $this->divisionB->id]))
            ->assertSessionHasErrors('enterprise_id');
    }

    public function test_only_a_super_admin_can_edit_users(): void
    {
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $this->farmA->id]);
        $user = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farmA->id]);

        $this->actingAs($manager)->put("/users/{$user->id}", $this->account())->assertForbidden();
        $this->actingAs($manager)->put("/users/{$user->id}/password", ['password' => 'new-pass-123'])->assertForbidden();
    }

    public function test_a_super_admin_account_cannot_be_edited_or_reset(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'super_admin', 'farm_id' => null]);

        $this->actingAs($this->admin)->put("/users/{$otherAdmin->id}", $this->account())->assertForbidden();
        $this->actingAs($this->admin)->put("/users/{$otherAdmin->id}/password", ['password' => 'new-pass-123'])->assertForbidden();
    }

    // --- resetPassword() ---

    public function test_password_reset_sets_the_new_password_and_revokes_scan_tokens(): void
    {
        $user = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farmA->id]);
        $user->createToken('scan-station', ["farm:{$this->farmA->id}"]);

        $this->actingAs($this->admin)->put("/users/{$user->id}/password", ['password' => 'new-pass-123'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue(Hash::check('new-pass-123', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_password_reset_ends_the_users_existing_session(): void
    {
        $user = User::factory()->create([
            'role' => 'farm_manager', 'farm_id' => $this->farmA->id, 'password' => Hash::make('old-pass-123'),
        ]);

        // A real login (not actingAs(), which never writes the session): auth.session records
        // the password hash this session was opened with.
        $this->post('/login', ['email' => $user->email, 'password' => 'old-pass-123'])->assertRedirect();
        $this->get('/profile')->assertOk();

        // What resetPassword() does to the stored password (its endpoint is covered above). Done
        // directly so this test's single session stays the user's own.
        $user->forceFill(['password' => Hash::make('changed-by-admin')])->save();

        // Next request on that session, with the guard re-reading the user from the DB as a new
        // request would: the stored hash no longer matches, so the session is logged out.
        auth()->forgetGuards();
        $this->get('/profile')->assertRedirect(route('login'));
    }

    public function test_password_reset_requires_eight_characters(): void
    {
        $user = User::factory()->create(['role' => 'data_entry', 'farm_id' => $this->farmA->id]);

        $this->actingAs($this->admin)->put("/users/{$user->id}/password", ['password' => 'short'])
            ->assertSessionHasErrors('password');
    }

    // --- Profile self-delete ---

    public function test_a_super_admin_cannot_delete_their_own_account(): void
    {
        $this->actingAs($this->admin)->delete('/profile', ['password' => 'password'])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }
}
