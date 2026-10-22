<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers EmployeeController::update()'s handling of the badge and CIN card photos: uploading
 * one must never fail or wipe out the other (attaching a file forces the whole request into
 * multipart/form-data, so Inertia's FormData serializer turns the untouched photo's `null` into
 * an empty string "" — Laravel's validator already treats "" as absent for a non-required rule
 * like `image`, so this is a genuine no-op, not a bug, but it's easy to accidentally reintroduce
 * one while touching this code, hence locking it down here), and the remove_photo/remove_cin_photo
 * flags (EmployeeFormModal.jsx's "×" buttons) must delete the stored file and null the path,
 * without touching the other photo, and must lose to an actual replacement upload if both are
 * somehow sent together.
 */
class EmployeePhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(): array
    {
        // Photos live on the private disk (EmployeeController::PHOTO_DISK); `public` is faked too so
        // tests can assert nothing lands there any more.
        Storage::fake('local');
        Storage::fake('public');

        $farm = Farm::create(['name' => 'Farm A']);
        $enterprise = Enterprise::create([
            'farm_id' => $farm->id, 'name' => 'Enterprise X',
            'contract_type' => 'avec_contrat', 'default_brut_rate' => 97.44,
        ]);
        $manager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $farm->id]);
        $employee = Employee::create([
            'farm_id' => $farm->id, 'enterprise_id' => $enterprise->id, 'matricule' => '039',
            'full_name' => 'AHANNI AZIZA', 'base_rate' => 97.44, 'is_active' => true,
        ]);

        return [$manager, $employee, $enterprise];
    }

    private function baseFields(Employee $employee, Enterprise $enterprise): array
    {
        return [
            'matricule' => $employee->matricule,
            'full_name' => $employee->full_name,
            'base_rate' => $employee->base_rate,
            'enterprise_id' => $enterprise->id,
        ];
    }

    public function test_uploading_only_the_badge_photo_does_not_fail_or_clear_the_cin_photo(): void
    {
        [$manager, $employee, $enterprise] = $this->makeEmployee();
        $employee->update(['cin_photo_path' => 'cin-cards/existing.jpg']);

        $this->actingAs($manager)
            ->post(route('employees.update', $employee), array_merge($this->baseFields($employee, $enterprise), [
                '_method' => 'put',
                'photo' => UploadedFile::fake()->image('badge.jpg'),
                'cin_photo' => '', // exactly what FormData serializes the untouched null field to
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNotNull($employee->photo_path);
        // The badge upload must not have wiped out the CIN photo that was already on file.
        $this->assertEquals('cin-cards/existing.jpg', $employee->cin_photo_path);
    }

    public function test_uploading_only_the_cin_photo_does_not_fail_or_clear_the_badge_photo(): void
    {
        [$manager, $employee, $enterprise] = $this->makeEmployee();
        $employee->update(['photo_path' => 'badges/existing.jpg']);

        $this->actingAs($manager)
            ->post(route('employees.update', $employee), array_merge($this->baseFields($employee, $enterprise), [
                '_method' => 'put',
                'cin_photo' => UploadedFile::fake()->image('cin.jpg'),
                'photo' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNotNull($employee->cin_photo_path);
        $this->assertEquals('badges/existing.jpg', $employee->photo_path);
    }

    public function test_editing_other_fields_via_multipart_leaves_both_existing_photos_untouched(): void
    {
        [$manager, $employee, $enterprise] = $this->makeEmployee();
        $employee->update(['photo_path' => 'badges/existing.jpg', 'cin_photo_path' => 'cin-cards/existing.jpg']);

        $this->actingAs($manager)
            ->post(route('employees.update', $employee), array_merge($this->baseFields($employee, $enterprise), [
                '_method' => 'put',
                'full_name' => 'Updated Name',
                'photo' => '',
                'cin_photo' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertEquals('Updated Name', $employee->full_name);
        $this->assertEquals('badges/existing.jpg', $employee->photo_path);
        $this->assertEquals('cin-cards/existing.jpg', $employee->cin_photo_path);
    }

    public function test_removing_the_badge_photo_deletes_the_file_and_clears_the_path(): void
    {
        [$manager, $employee, $enterprise] = $this->makeEmployee();
        $path = UploadedFile::fake()->image('badge.jpg')->store('badges', 'local');
        $employee->update(['photo_path' => $path]);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($manager)
            ->post(route('employees.update', $employee), array_merge($this->baseFields($employee, $enterprise), [
                '_method' => 'put',
                'remove_photo' => true,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNull($employee->photo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_removing_the_cin_photo_deletes_the_file_and_clears_the_path(): void
    {
        [$manager, $employee, $enterprise] = $this->makeEmployee();
        $path = UploadedFile::fake()->image('cin.jpg')->store('cin-cards', 'local');
        $employee->update(['cin_photo_path' => $path]);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($manager)
            ->post(route('employees.update', $employee), array_merge($this->baseFields($employee, $enterprise), [
                '_method' => 'put',
                'remove_cin_photo' => true,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNull($employee->cin_photo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_removing_the_badge_photo_leaves_the_cin_photo_untouched(): void
    {
        [$manager, $employee, $enterprise] = $this->makeEmployee();
        $employee->update(['photo_path' => 'badges/existing.jpg', 'cin_photo_path' => 'cin-cards/existing.jpg']);

        $this->actingAs($manager)
            ->post(route('employees.update', $employee), array_merge($this->baseFields($employee, $enterprise), [
                '_method' => 'put',
                'remove_photo' => true,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNull($employee->photo_path);
        $this->assertEquals('cin-cards/existing.jpg', $employee->cin_photo_path);
    }

    public function test_uploading_a_replacement_badge_photo_wins_over_a_stale_remove_flag(): void
    {
        [$manager, $employee, $enterprise] = $this->makeEmployee();
        $employee->update(['photo_path' => 'badges/existing.jpg']);

        // remove_photo=true alongside a new file shouldn't happen from the UI (picking a new
        // file hides the remove button), but the controller must still prefer the upload.
        $this->actingAs($manager)
            ->post(route('employees.update', $employee), array_merge($this->baseFields($employee, $enterprise), [
                '_method' => 'put',
                'photo' => UploadedFile::fake()->image('new-badge.jpg'),
                'remove_photo' => true,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $employee->refresh();
        $this->assertNotNull($employee->photo_path);
        $this->assertNotEquals('badges/existing.jpg', $employee->photo_path);
    }

    // --- Photos are private: never on the public disk, only served through employees.photo ---

    public function test_uploaded_photos_are_stored_on_the_private_disk_only(): void
    {
        [$manager, $employee, $enterprise] = $this->makeEmployee();

        $this->actingAs($manager)->post('/employees/' . $employee->id, array_merge($this->baseFields($employee, $enterprise), [
            '_method' => 'PUT',
            'photo' => UploadedFile::fake()->image('badge.jpg'),
            'cin_photo' => UploadedFile::fake()->image('cin.jpg'),
        ]))->assertSessionHasNoErrors();

        $employee->refresh();
        Storage::disk('local')->assertExists([$employee->photo_path, $employee->cin_photo_path]);
        Storage::disk('public')->assertMissing([$employee->photo_path, $employee->cin_photo_path]);
    }

    public function test_photo_route_serves_the_cin_scan_to_the_employees_farm_without_caching(): void
    {
        [$manager, $employee] = $this->makeEmployee();
        $path = UploadedFile::fake()->image('cin.jpg')->store('cin-cards', 'local');
        $employee->update(['cin_photo_path' => $path]);

        $response = $this->actingAs($manager)->get(route('employees.photo', [$employee, 'cin']));

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_photo_route_refuses_another_farm_and_guests(): void
    {
        [, $employee] = $this->makeEmployee();
        $path = UploadedFile::fake()->image('cin.jpg')->store('cin-cards', 'local');
        $employee->update(['cin_photo_path' => $path]);
        $otherFarm = Farm::create(['name' => 'Farm B']);
        $otherManager = User::factory()->create(['role' => 'farm_manager', 'farm_id' => $otherFarm->id]);

        $this->actingAs($otherManager)->get(route('employees.photo', [$employee, 'cin']))->assertForbidden();

        auth()->logout();
        $this->get(route('employees.photo', [$employee, 'cin']))->assertRedirect(route('login'));
    }

    public function test_photo_route_returns_404_when_there_is_no_photo(): void
    {
        [$manager, $employee] = $this->makeEmployee();

        $this->actingAs($manager)->get(route('employees.photo', [$employee, 'photo']))->assertNotFound();
        $this->actingAs($manager)->get('/employees/' . $employee->id . '/photo/other')->assertNotFound();
    }

    public function test_badges_print_with_a_photo_from_the_private_disk(): void
    {
        [$manager, $employee] = $this->makeEmployee();
        $path = UploadedFile::fake()->image('badge.jpg', 120, 160)->store('badges', 'local');
        $employee->update(['photo_path' => $path]);

        $response = $this->actingAs($manager)->get(route('badges.print', ['employee_ids' => $employee->id]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_migration_moves_existing_photos_off_the_public_disk(): void
    {
        [, $employee] = $this->makeEmployee();
        Storage::disk('public')->put('cin-cards/old.jpg', 'id-card');
        Storage::disk('public')->put('badges/old.jpg', 'face');
        $employee->update(['cin_photo_path' => 'cin-cards/old.jpg', 'photo_path' => 'badges/old.jpg']);

        (require database_path('migrations/2026_01_01_000044_move_employee_photos_to_private_disk.php'))->up();

        Storage::disk('public')->assertMissing(['cin-cards/old.jpg', 'badges/old.jpg']);
        $this->assertSame('id-card', Storage::disk('local')->get('cin-cards/old.jpg'));
        $this->assertSame('face', Storage::disk('local')->get('badges/old.jpg'));
    }
}
