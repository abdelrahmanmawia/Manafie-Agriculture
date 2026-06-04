<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Enterprise;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Bloc;
use App\Models\Quinzaine;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Enterprise
        $ferme = Enterprise::create([
            'name' => 'Ferme PerseaLand',
            'settings' => ['currency' => 'DH']
        ]);

        // 2. Create Admin Users
        User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'role' => 'super_admin',
            'enterprise_id' => null,
        ]);

        User::factory()->create([
            'name' => 'Ferme Admin',
            'email' => 'ferme@example.com',
            'role' => 'enterprise_admin',
            'enterprise_id' => $ferme->id,
        ]);

        // 3. Create Blocs and Operations
        $blocA = Bloc::create(['name' => 'Bloc A', 'enterprise_id' => $ferme->id]);
        $blocB = Bloc::create(['name' => 'Bloc B', 'enterprise_id' => $ferme->id]);

        $opHarvest = Operation::create(['name' => 'Recolte', 'enterprise_id' => $ferme->id]);
        $opPrune = Operation::create(['name' => 'Taille', 'enterprise_id' => $ferme->id]);

        // 4. Create Employees
        Employee::create([
            'enterprise_id' => $ferme->id,
            'matricule' => 'EMP001',
            'full_name' => 'Mohamed Alami',
            'type' => 'persea',
            'base_rate' => 15.50
        ]);

        Employee::create([
            'enterprise_id' => $ferme->id,
            'matricule' => 'EMP002',
            'full_name' => 'Fatima Zahra',
            'type' => 'hafila',
            'base_rate' => 14.00
        ]);

        // 5. Create Quinzaine
        Quinzaine::create([
            'enterprise_id' => $ferme->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-15',
            'is_closed' => false
        ]);
    }
}
