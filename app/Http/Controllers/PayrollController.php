<?php

namespace App\Http\Controllers;

use App\Exports\HistoriquePaieExport;
use App\Exports\PointageExport;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\Quinzaine;
use App\Models\PointageRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

use App\Services\HistoriquePaieService;
use App\Services\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;

class PayrollController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    public function downloadGeneralPayslip(Request $request, $quinzaineId)
    {
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request, $quinzaine);

        // Whoever has a record in this quinzaine (a worker since moved to another division too):
        // the sheet to sign lists, numbers and counts them, nobody else of the division.
        $employees = Employee::whereIn('id', PointageRecord::where('quinzaine_id', $quinzaine->id)->select('employee_id'))
            ->orderBy('full_name')->get();

        $records = PointageRecord::where('quinzaine_id', $quinzaineId)
            ->get()
            ->groupBy('employee_id');

        $pdf = Pdf::loadView('exports.general_payslip', [
            'quinzaine' => $quinzaine,
            'employees' => $employees,
            'records' => $records,
        ])->setPaper('a4', 'portrait');

        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');

        return $pdf->download('Etat_Global_'.$cleanLabel.'.pdf');
    }

    public function downloadPayslip(Request $request, $employeeId, $quinzaineId)
    {
        $employee = Employee::findOrFail($employeeId);
        $quinzaine = Quinzaine::with('enterprise')->findOrFail($quinzaineId);
        $this->assertQuinzaineInScope($request, $quinzaine);
        // Employee is farm-scoped, not enterprise-scoped (the same real worker can have pointage
        // under several enterprises of the same farm over time) — so the real invariant is same
        // farm, not same enterprise; never loosens across farms.
        abort_unless($employee->farm_id === $quinzaine->enterprise->farm_id, 403);

        $records = PointageRecord::where('employee_id', $employeeId)
            ->where('quinzaine_id', $quinzaineId)
            ->get();

        if ($records->isEmpty()) {
            return redirect()->back()->with('error', 'Aucun pointage trouvé pour ce salarié.');
        }

        // Every line (and the totals) comes from PayrollService::payslipBreakdown() — see there
        // for what the hand-rolled math that used to live here got wrong.
        $declare = PointageExport::ficheFor($quinzaine->enterprise) !== PointageExport::FICHE_NON_DECLAREE;
        $payslip = $this->payrollService->payslipBreakdown(
            $quinzaine->enterprise->contract_type,
            $records,
            $employee->salaire_mensuel !== null ? (float) $employee->salaire_mensuel : null,
            $declare
        );

        $pdf = Pdf::loadView('exports.payslip', [
            'employee' => $employee,
            'quinzaine' => $quinzaine,
            'records' => $records,
            'payslip' => $payslip,
            'declare' => $declare,
        ]);

        // Same filename sanitizing as PointageController::export() — a label like "1QZ 01/2026"
        // would otherwise put a path separator in the download name.
        $cleanLabel = str_replace([' ', '/', '\\'], '_', $quinzaine->label ?: 'Pointage');

        return $pdf->download('Bulletin_' . $employee->matricule . '_' . $cleanLabel . '.pdf');
    }

    public function history(Request $request, HistoriquePaieService $historique)
    {
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);

        if (!$enterpriseId && $request->user()->role !== 'super_admin' && !$farmId) {
            abort(403);
        }

        $donnees = $historique->calculer($farmId, $enterpriseId);

        return Inertia::render('Payroll/History', [
            'employees' => $donnees['employees'],
            'periods' => $donnees['periods'],
            'history' => (object) $donnees['history'],
            'selectedEnterpriseId' => $enterpriseId,
            'allEnterprises' => $this->selectableEnterprises($request),
            'equipes' => \App\Models\Equipe::where('farm_id', $farmId)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The table as shown: the same division, periods, équipe, search and "with an amount" filter. */
    public function historyExport(Request $request, HistoriquePaieService $historique)
    {
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);

        $validated = $request->validate([
            'periode' => 'nullable|string|max:30',
            'equipe' => 'nullable|string|max:20',
            'q' => 'nullable|string|max:100',
            'avec_montant' => 'nullable|boolean',
        ]);

        return Excel::download(new HistoriquePaieExport(
            $historique->filtrer($historique->calculer($farmId, $enterpriseId), $validated),
            $enterpriseId ? Enterprise::find($enterpriseId)?->name : 'Toutes les divisions'
        ), 'Historique_Paie_'.now()->format('Y-m-d').'.xlsx');
    }
}
