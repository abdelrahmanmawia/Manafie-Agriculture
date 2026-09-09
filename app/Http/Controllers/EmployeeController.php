<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\TransportLocation;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Employees are Pointage-domain master data: farm_manager (or super_admin working the
     * active farm) may create/delete/toggle, as can a data_entry granted Pointage access
     * (canAccessPointage()) — never a stock-only data_entry. Also locked to its own enterprise
     * when it has one.
     */
    private function assertEmployeeManagerAccess(Request $request, int $farmId): void
    {
        $user = $request->user();
        abort_unless($user->canAccessPointage(), 403);

        if ($user->role === 'super_admin') {
            abort_unless((int) session('active_farm_id') === $farmId, 403);
            return;
        }

        abort_unless($user->farm_id === $farmId, 403);
    }

    /**
     * Looser check used by update(), which data_entry is allowed to call: still requires
     * the employee's farm to match the actor's scope, and further locks an enterprise-scoped
     * data_entry to their own enterprise_id.
     */
    private function assertEmployeeInScope(Request $request, Employee $employee): void
    {
        $user = $request->user();
        $farmId = $user->role === 'super_admin' ? session('active_farm_id') : $user->farm_id;

        abort_unless($farmId && $employee->farm_id === (int) $farmId, 403);

        if ($user->enterprise_id) {
            abort_unless($employee->enterprise_id === $user->enterprise_id, 403);
        }
    }

    /**
     * Validates that $enterpriseId is one the current actor is allowed to assign an
     * employee to (used by store()/update() to stop a cross-farm enterprise_id in the payload).
     */
    private function assertEnterpriseAssignable(Request $request, int $enterpriseId): void
    {
        $user = $request->user();
        $farmId = $user->role === 'super_admin' ? session('active_farm_id') : $user->farm_id;

        $enterprise = \App\Models\Enterprise::find($enterpriseId);
        abort_unless($enterprise && $farmId && $enterprise->farm_id === (int) $farmId, 403);

        if ($user->enterprise_id) {
            abort_unless($enterpriseId === $user->enterprise_id, 403);
        }
    }

    /**
     * Divisions the current user is allowed to assign an employee to — same rule the
     * EmployeeFormModal's "Assigner à une Division" select needs whether it's opened from the
     * list (index()) or the detail page (show()).
     */
    private function assignableEnterprisesFor($user)
    {
        return $user->role === 'super_admin'
            ? \App\Models\Enterprise::where('farm_id', session('active_farm_id'))->get()
            : (($user->role === 'farm_manager' || ($user->role === 'data_entry' && !$user->enterprise_id))
                ? \App\Models\Enterprise::where('farm_id', $user->farm_id)->get()
                : []);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $enterpriseId = $user->enterprise_id ?? $request->query('enterprise_id');
        $searchQuery = $request->query('search');

        // Employee is farm-scoped (the same real person can have pointage under several
        // enterprises of the farm over time); enterprise_id here only narrows which division's
        // employees to show, it's never the scoping boundary itself.
        $query = Employee::with('enterprise');

        if ($user->role === 'super_admin') {
            $query->where('farm_id', session('active_farm_id'));
            if ($enterpriseId) {
                $query->where('enterprise_id', $enterpriseId);
            }
        } elseif ($user->role === 'farm_manager') {
            $query->where('farm_id', $user->farm_id);
            if ($enterpriseId) {
                $query->where('enterprise_id', $enterpriseId);
            }
        } elseif ($enterpriseId) {
            $query->where('enterprise_id', $enterpriseId);
        } elseif ($user->farm_id) {
            $query->where('farm_id', $user->farm_id);
        }

        if ($searchQuery) {
            $query->where(function($q) use ($searchQuery) {
                $q->where('matricule', 'like', '%' . $searchQuery . '%')
                  ->orWhere('full_name', 'like', '%' . $searchQuery . '%')
                  ->orWhere('cin', 'like', '%' . $searchQuery . '%')
                  ->orWhere('phone', 'like', '%' . $searchQuery . '%');
            });
        }

        $enterprises = $this->assignableEnterprisesFor($user);

        $farmId = $this->scopedFarmId($request);

        return Inertia::render('Admin/Employees', [
            'employees' => $query->get(),
            'enterprises' => $enterprises,
            'selectedEnterpriseId' => $enterpriseId,
            'searchQuery' => $searchQuery,
            'transportLocations' => TransportLocation::where('farm_id', $farmId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price_per_person']),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertEnterpriseAssignable($request, (int) $request->enterprise_id);
        abort_unless($request->user()->canAccessPointage(), 403);

        $farmId = \App\Models\Enterprise::findOrFail($request->enterprise_id)->farm_id;

        $validated = $request->validate([
            // matricule is only unique within a farm — the same real person keeps ONE Employee
            // row as they move between the farm's divisions over time.
            'matricule' => ['required', 'string', Rule::unique('employees')->where(
                fn ($query) => $query->where('farm_id', $farmId)
            )],
            // CIN is the real dedup identity (matches the DB's unique(farm_id, cin)) — validated
            // here too so a collision surfaces as a normal form error, not a raw DB exception.
            'cin' => ['nullable', 'string', 'max:50', Rule::unique('employees')->where(
                fn ($query) => $query->where('farm_id', $farmId)
            )],
            'full_name' => 'required|string|max:255',
            'cnss_number' => 'nullable|string|max:50',
            'dob' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'rib' => 'nullable|string|max:100',
            'base_rate' => 'required|numeric|min:0',
            'enterprise_id' => 'required|exists:enterprises,id',
            'residence_location_id' => ['nullable', Rule::exists('transport_locations', 'id')->where('farm_id', $farmId)],
        ]);

        Employee::create(array_merge($validated, [
            'farm_id' => $farmId,
            'is_active' => true
        ]));

        return redirect()->back();
    }

    public function update(Request $request, Employee $employee)
    {
        $this->assertEmployeeInScope($request, $employee);

        $validated = $request->validate([
            // Employee's farm_id is fixed (set once at creation) — matricule/CIN uniqueness is
            // scoped to it, not to whichever enterprise they're being reassigned to.
            'matricule' => ['required', 'string', Rule::unique('employees')->where(
                fn ($query) => $query->where('farm_id', $employee->farm_id)
            )->ignore($employee->id)],
            'full_name' => 'required|string|max:255',
            'cin' => ['nullable', 'string', 'max:50', Rule::unique('employees')->where(
                fn ($query) => $query->where('farm_id', $employee->farm_id)
            )->ignore($employee->id)],
            'cnss_number' => 'nullable|string|max:50',
            'dob' => 'nullable|date',
            'hire_date' => 'nullable|date',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:100',
            'rib' => 'nullable|string|max:100',
            'base_rate' => 'required|numeric|min:0',
            'complement' => 'nullable|numeric|min:0',
            'enterprise_id' => 'required|exists:enterprises,id',
            'residence_location_id' => ['nullable', Rule::exists('transport_locations', 'id')->where('farm_id', $employee->farm_id)],
            // Sent as a real JS boolean on a plain Inertia PUT, but as the literal string
            // "true"/"false" once a photo file forces the request into multipart/FormData —
            // Laravel's `boolean` rule strictly rejects those strings (only true/false/0/1/'0'/'1'),
            // so accept them here and coerce below via $request->boolean() rather than trusting
            // the raw validated value (casting the STRING "false" with PHP's (bool) is true).
            'is_active' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'photo' => 'nullable|image|max:5120',
        ]);

        if (array_key_exists('is_active', $validated)) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $this->assertEnterpriseAssignable($request, (int) $validated['enterprise_id']);

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                Storage::disk('public')->delete($employee->photo_path);
            }
            $validated['photo_path'] = $request->file('photo')->store('badges', 'public');
        }
        unset($validated['photo']);

        $employee->update($validated);

        // Keep this employee's still-open pointage in sync with the new complement — otherwise
        // their stored net/brut stay priced at whatever complement was in effect when each cell
        // was entered, silently diverging from what a payslip would compute for the same day.
        if ($employee->wasChanged('complement')) {
            $this->payrollService->recalculateOpenRecordsForEmployee($employee);
        }

        return redirect()->back();
    }

    public function toggleActive(Request $request, Employee $employee)
    {
        $this->assertEmployeeManagerAccess($request, $employee->farm_id);

        $employee->update(['is_active' => !$employee->is_active]);
        return redirect()->back();
    }

    /**
     * Full profile + payroll stats for one employee — the Employees list table only shows the
     * essentials (matricule, name, CIN, phone, daily net, status); everything else (address,
     * bank/RIB, dates, transport, and how much they've actually earned) lives here instead.
     */
    public function show(Request $request, Employee $employee)
    {
        $this->assertEmployeeInScope($request, $employee);

        $employee->load(['enterprise', 'residenceLocation', 'transportVehicle.transportCompany']);

        $totalDays = PointageRecord::where('employee_id', $employee->id)->count();
        $totalNet = (float) PointageRecord::where('employee_id', $employee->id)->sum('net');

        // The employee's own currently-open pay period (if any) — same "days worked / net so
        // far" shape as the recent-quinzaines list below, surfaced separately since it's the
        // one a manager checks most often.
        $openQuinzaine = Quinzaine::where('enterprise_id', $employee->enterprise_id)
            ->where('is_closed', false)
            ->orderByDesc('start_date')
            ->first();
        $currentPeriod = $openQuinzaine ? [
            'label' => $openQuinzaine->label,
            'days' => PointageRecord::where('employee_id', $employee->id)->where('quinzaine_id', $openQuinzaine->id)->count(),
            'net' => (float) PointageRecord::where('employee_id', $employee->id)->where('quinzaine_id', $openQuinzaine->id)->sum('net'),
        ] : null;

        // Last few quinzaines this employee actually has records in — not every quinzaine of
        // the enterprise, since most won't involve them if they joined partway through.
        $recentQuinzaines = Quinzaine::where('enterprise_id', $employee->enterprise_id)
            ->whereHas('pointageRecords', fn ($q) => $q->where('employee_id', $employee->id))
            ->withCount(['pointageRecords as days' => fn ($q) => $q->where('employee_id', $employee->id)])
            ->withSum(['pointageRecords as net' => fn ($q) => $q->where('employee_id', $employee->id)], 'net')
            ->orderByDesc('start_date')
            ->take(6)
            ->get(['id', 'label', 'start_date', 'end_date', 'is_closed']);

        $farmId = $this->scopedFarmId($request);

        return Inertia::render('Admin/Employees/Show', [
            'employee' => $employee,
            'stats' => [
                'total_days' => $totalDays,
                'total_net' => $totalNet,
                'current_period' => $currentPeriod,
            ],
            'recentQuinzaines' => $recentQuinzaines,
            // For the Modifier/Générer Contrat/Supprimer actions on this page — same shared
            // EmployeeFormModal the list page uses, so it needs the same lookups.
            'enterprises' => $this->assignableEnterprisesFor($request->user()),
            'transportLocations' => TransportLocation::where('farm_id', $farmId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'price_per_person']),
        ]);
    }

    /**
     * Seasonal-worker CDD (fixed-term agricultural contract), pre-filled from the employee's
     * own record — same access boundary as update() (assertEmployeeInScope), since this exposes
     * the employee's CIN/address/CNSS number, not just farm-structure data. The employer party
     * (AGRI-INTERIM Sarl, its RC/CNSS numbers) is fixed text baked into the template — it's the
     * one legal entity behind every one of these contracts, not something that varies by farm
     * or enterprise.
     */
    public function generateContract(Request $request, Employee $employee)
    {
        $this->assertEmployeeInScope($request, $employee);

        // Not $request->validate(): an empty `?start_date=` (e.g. the frontend's date picker
        // cleared) is a non-null string, which the `date` rule rejects — validate() would then
        // redirect back with errors on this plain <a> GET link, which looks indistinguishable
        // from the page just reloading with nothing happening. Parse defensively instead so a
        // bad value quietly falls back to hire_date rather than silently failing the download.
        $startDateInput = trim((string) $request->query('start_date'));
        try {
            $startDate = $startDateInput !== '' ? Carbon::parse($startDateInput) : null;
        } catch (\Exception $e) {
            $startDate = null;
        }
        $startDate ??= $employee->hire_date ?? now();

        $html = view('exports.contract', [
            'employee' => $employee,
            'startDate' => Carbon::parse($startDate),
        ])->render();

        // dompdf (this app's usual PDF engine, see PayrollService/payslip exports) has no real
        // Arabic bidi/shaping support: it can be coaxed into rendering RTL text so it LOOKS
        // right on screen, but the underlying PDF text-showing operators still come out in
        // reversed/mirrored character order, so copy-pasting or searching the Arabic text is
        // permanently broken. mPDF has a proper OpenType Arabic shaping + bidi engine, producing
        // both correct visual rendering AND correct logical-order text — used here instead,
        // scoped to just this contract feature (payslips keep using dompdf).
        $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];
        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $mpdf = new \Mpdf\Mpdf([
            // Not 'mode' => 'ar': mPDF treats a recognized language code passed as `mode` as an
            // instruction to pick ITS OWN bundled Unicode font for that language (xbriyaz for
            // Arabic), silently overriding `default_font` below — so every Arabic run got drawn
            // with xbriyaz instead of Tajawal, and (for reasons not fully chased down) that
            // built-in font's shaping came out as disconnected isolated letters on the actual
            // rendered page, even though its ToUnicode text layer still looked correct. RTL
            // direction is already fully handled by `directionality` below without needing `mode`.
            'format' => 'A4',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 15,
            'margin_bottom' => 15,
            'fontDir' => array_merge($fontDirs, [resource_path('fonts')]),
            'fontdata' => $fontData + [
                // useOTL enables OpenType Layout processing (Arabic contextual glyph shaping —
                // choosing the initial/medial/final/isolated form of each letter — plus kashida).
                // mPDF's own bundled fonts all set this; a custom font registered without it
                // still gets correct logical text in the file (ToUnicode/copy-paste), but mPDF
                // draws every letter in isolated form on the page itself, so words look visually
                // disconnected even though the underlying text is right.
                'tajawal' => [
                    'R' => 'Tajawal-Regular.ttf',
                    'B' => 'Tajawal-Bold.ttf',
                    'useOTL' => 0xFF,
                ],
            ],
            'default_font' => 'tajawal',
            'directionality' => 'rtl',
        ]);
        $mpdf->WriteHTML($html);

        // An employee with no hire_date defaults start_date to "today" every time, so the exact
        // same URL (?start_date=2026-09-08) gets hit repeatedly the same day — without this, the
        // browser can silently serve back a cached PDF from before the very template change
        // meant to fix it, making a real fix look like it "didn't work".
        return response($mpdf->Output('Contrat_' . $employee->matricule . '.pdf', \Mpdf\Output\Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Contrat_' . $employee->matricule . '.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function destroy(Request $request, Employee $employee)
    {
        $this->assertEmployeeManagerAccess($request, $employee->farm_id);

        $employee->delete();
        // Not redirect()->back(): when deletion is triggered from the employee's own Show page
        // (see EmployeeFormModal usage there), "back" would just redirect to that same
        // now-404'd URL. The list is the one place always safe to land on regardless of which
        // page the delete came from.
        return redirect()->route('employees.index');
    }
}
