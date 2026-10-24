<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Equipe;
use App\Models\TransportVehicle;
use App\Services\SalaireFixeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Chef d'équipe teams — the unit of the first pointage step (Grid "Par équipe"). Members are
 * picked per équipe with a checklist (syncMembers()), same pattern the vehicle riders used to
 * have; the vehicle an équipe rides is how its members travel (TransportVehicle::employees()).
 */
class EquipeController extends Controller
{
    /**
     * An équipe groups workers of several divisions, so a user locked to one division can
     * view the list but not reshape teams that reach into sibling divisions.
     */
    private function assertCanManage(Request $request): void
    {
        abort_if($request->user()->enterprise_id, 403);
    }

    public function index(Request $request)
    {
        $farmId = $this->scopedFarmId($request);

        return Inertia::render('Pointage/Equipes', [
            'equipes' => Equipe::with(['chef:id,full_name', 'transportVehicle:id,code,is_active'])
                ->withCount('employees')
                ->where('farm_id', $farmId)
                ->orderBy('name')
                ->get(),
            'employees' => Employee::with('enterprise:id,name')
                ->where('farm_id', $farmId)
                ->where('is_active', true)
                ->orderBy('full_name')
                ->get(['id', 'matricule', 'full_name', 'equipe_id', 'enterprise_id']),
            'vehicles' => TransportVehicle::where('farm_id', $farmId)->where('is_active', true)->orderBy('code')->get(['id', 'code']),
            'canManage' => ! $request->user()->enterprise_id,
        ]);
    }

    public function store(Request $request)
    {
        $this->assertCanManage($request);
        $farmId = $this->resolveWriteFarmId($request);

        $validated = $request->validate($this->rules($farmId) + [
            'name' => ['required', 'string', 'max:100', Rule::unique('equipes')->where('farm_id', $farmId)],
        ]);

        DB::transaction(function () use ($validated, $farmId) {
            $equipe = Equipe::create($validated + ['farm_id' => $farmId]);
            $this->chefJoins($equipe);
        });

        return redirect()->back()->with('success', 'Équipe créée.');
    }

    public function update(Request $request, Equipe $equipe)
    {
        $this->assertCanManage($request);
        $this->assertFarmScope($request, $equipe->farm_id);

        $validated = $request->validate($this->rules($equipe->farm_id) + [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('equipes')->where('farm_id', $equipe->farm_id)->ignore($equipe->id)],
            'is_active' => 'sometimes|boolean',
        ]);

        $switched = DB::transaction(function () use ($equipe, $validated) {
            $equipe->update($validated);
            $this->chefJoins($equipe);

            return $equipe->wasChanged('is_active') ? $this->switchMembers($equipe) : null;
        });

        return redirect()->back()->with('success', $switched === null ? 'Équipe mise à jour.'
            : ($equipe->is_active ? "Équipe réactivée avec {$switched} ouvrier(s)." : "Équipe désactivée avec ses {$switched} ouvrier(s)."));
    }

    public function destroy(Request $request, Equipe $equipe)
    {
        $this->assertCanManage($request);
        $this->assertFarmScope($request, $equipe->farm_id);

        if ($equipe->employees()->exists()) {
            return redirect()->back()->withErrors([
                'equipe' => 'Cette équipe a encore des ouvriers. Retirez-les (ou désactivez l\'équipe) avant de la supprimer.',
            ]);
        }

        $equipe->delete();

        return redirect()->back()->with('success', 'Équipe supprimée.');
    }

    /**
     * Sets the équipe's members to exactly the checked list. A worker already in another équipe
     * moves to this one (a worker is in one équipe at a time). The chef stays the chef only
     * while still a member.
     */
    public function syncMembers(Request $request, Equipe $equipe)
    {
        $this->assertCanManage($request);
        $this->assertFarmScope($request, $equipe->farm_id);

        $validated = $request->validate([
            'employee_ids' => 'array',
            'employee_ids.*' => [Rule::exists('employees', 'id')->where('farm_id', $equipe->farm_id)],
        ]);
        $employeeIds = $validated['employee_ids'] ?? [];

        DB::transaction(function () use ($equipe, $employeeIds) {
            Employee::where('equipe_id', $equipe->id)->whereNotIn('id', $employeeIds)->update(['equipe_id' => null]);
            Employee::whereIn('id', $employeeIds)->update(['equipe_id' => $equipe->id]);

            if ($equipe->chef_employee_id && ! in_array($equipe->chef_employee_id, $employeeIds)) {
                $equipe->update(['chef_employee_id' => null]);
            }
            // Someone who was chef of the équipe they just left isn't its chef any more.
            Equipe::whereIn('chef_employee_id', $employeeIds)->where('id', '!=', $equipe->id)->update(['chef_employee_id' => null]);
        });

        return redirect()->back()->with('success', 'Ouvriers de l\'équipe mis à jour.');
    }

    private function rules(int $farmId): array
    {
        return [
            'chef_employee_id' => ['nullable', Rule::exists('employees', 'id')->where('farm_id', $farmId)],
            'transport_vehicle_id' => ['nullable', Rule::exists('transport_vehicles', 'id')->where('farm_id', $farmId)],
        ];
    }

    /**
     * The members follow their équipe: deactivating it deactivates its active members (marked
     * desactive_avec_equipe), reactivating it brings back exactly those — a member deactivated on
     * their own stays so. Returns how many were switched.
     */
    private function switchMembers(Equipe $equipe): int
    {
        $members = $equipe->is_active
            ? $equipe->employees()->where('is_active', false)->where('desactive_avec_equipe', true)->get()
            : $equipe->employees()->where('is_active', true)->get();

        foreach ($members as $employee) {
            $employee->update(['is_active' => $equipe->is_active, 'desactive_avec_equipe' => ! $equipe->is_active]);
            if ($employee->salaire_mensuel !== null) {
                app(SalaireFixeService::class)->syncEmployee($employee);
            }
        }

        return $members->count();
    }

    /** The chef is a member of their own équipe (leaving any other, and its chef role there). */
    private function chefJoins(Equipe $equipe): void
    {
        if (! $equipe->chef_employee_id) {
            return;
        }

        Equipe::where('chef_employee_id', $equipe->chef_employee_id)->where('id', '!=', $equipe->id)->update(['chef_employee_id' => null]);
        Employee::whereKey($equipe->chef_employee_id)->update(['equipe_id' => $equipe->id]);
    }
}
