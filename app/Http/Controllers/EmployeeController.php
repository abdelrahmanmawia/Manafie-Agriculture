<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PointageRecord;
use App\Models\Quinzaine;
use App\Models\TransportLocation;
use App\Services\PayrollService;
use App\Services\SalaireFixeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    /**
     * Badge and CIN photos live on the private `local` disk (storage/app), never `public`:
     * anything on `public` is served by the web server to anyone holding the URL, with no login
     * — and a CIN scan is a national ID card. They're only reachable through photo() below,
     * which applies the same scope check as viewing the employee. Migration 000044 moved the
     * files that were already on `public`.
     */
    public const PHOTO_DISK = 'local';

    protected $payrollService;

    public function __construct(PayrollService $payrollService)
    {
        $this->payrollService = $payrollService;
    }

    /**
     * Employees are Pointage-domain master data: farm_manager (or super_admin working the
     * active farm) may create/delete/toggle, as can a data_entry granted Pointage access
     * (canAccessPointage()) — never a stock-only data_entry. Same farm/division boundary as
     * viewing or editing the employee (Controller::assertEmployeeInScope()); it used to skip
     * the division lock, so a division-locked user could delete or deactivate a sibling
     * division's workers.
     */
    private function assertEmployeeManagerAccess(Request $request, Employee $employee): void
    {
        abort_unless($request->user()->canAccessPointage(), 403);
        $this->assertEmployeeInScope($request, $employee);
    }

    public function index(Request $request)
    {
        $searchQuery = $request->query('search');
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);

        // Employee is farm-scoped (the same real person can have pointage under several
        // enterprises of the farm over time); enterprise_id here only narrows which division's
        // employees to show, it's never the scoping boundary itself. The farm filter applies to
        // every role unconditionally — a farm-scoped data_entry used to get only the
        // enterprise_id filter when one was passed, so ?enterprise_id=<another farm's division>
        // listed that farm's employees (CIN, RIB, phone, address).
        $query = Employee::with('enterprise')
            ->where('farm_id', $farmId)
            ->when($enterpriseId, fn ($q) => $q->where('enterprise_id', $enterpriseId));

        if ($searchQuery) {
            $query->where(function($q) use ($searchQuery) {
                $q->where('matricule', 'like', '%' . $searchQuery . '%')
                  ->orWhere('full_name', 'like', '%' . $searchQuery . '%')
                  ->orWhere('cin', 'like', '%' . $searchQuery . '%')
                  ->orWhere('phone', 'like', '%' . $searchQuery . '%');
            });
        }

        $enterprises = $this->selectableEnterprises($request);

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
        abort_unless($request->user()->canAccessPointage(), 403);
        $farmId = $this->assertEnterpriseInScope($request, (int) $request->enterprise_id)->farm_id;

        $validated = $request->validate([
            // matricule is not an input: it is generated below (sequential per farm) so nobody
            // can duplicate or change it by hand.
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

        DB::transaction(function () use ($validated, $farmId) {
            Employee::create(array_merge($validated, [
                'farm_id' => $farmId,
                'matricule' => $this->nextMatricule($farmId),
                'is_active' => true,
            ]));
        });

        return redirect()->back();
    }

    /** Next sequential matricule for a farm: one more than the highest numeric one, zero-padded. */
    private function nextMatricule(int $farmId): string
    {
        $max = Employee::where('farm_id', $farmId)->lockForUpdate()->pluck('matricule')
            ->filter(fn ($m) => ctype_digit((string) $m))->map(fn ($m) => (int) $m)->max() ?? 0;

        return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    public function update(Request $request, Employee $employee)
    {
        $this->assertEmployeeInScope($request, $employee);

        $validated = $request->validate([
            // Employee's farm_id is fixed (set once at creation) — CIN uniqueness is scoped to it,
            // not to whichever enterprise they're being reassigned to. The matricule is never
            // editable (auto-assigned at creation), so it isn't validated or read here.
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
            // A fixed monthly salary replaces the day pay (SalaireFixeService); empty = paid by the day.
            'salaire_mensuel' => 'nullable|numeric|min:0',
            'enterprise_id' => 'required|exists:enterprises,id',
            'residence_location_id' => ['nullable', Rule::exists('transport_locations', 'id')->where('farm_id', $employee->farm_id)],
            // Sent as a real JS boolean on a plain Inertia PUT, but as the literal string
            // "true"/"false" once a photo file forces the request into multipart/FormData —
            // Laravel's `boolean` rule strictly rejects those strings (only true/false/0/1/'0'/'1'),
            // so accept them here and coerce below via $request->boolean() rather than trusting
            // the raw validated value (casting the STRING "false" with PHP's (bool) is true).
            'is_active' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'photo' => 'nullable|image|max:5120',
            'cin_photo' => 'nullable|image|max:5120',
            // Same string-vs-boolean multipart coercion as `is_active` — the "remove this photo"
            // buttons in EmployeeFormModal set these to flag deletion of an existing photo when
            // no replacement file is chosen.
            'remove_photo' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'remove_cin_photo' => ['sometimes', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
        ]);

        if (array_key_exists('is_active', $validated)) {
            $validated['is_active'] = $request->boolean('is_active');
            $validated['desactive_avec_equipe'] = false;
        }

        $this->assertEnterpriseInScope($request, (int) $validated['enterprise_id']);

        if ($request->hasFile('photo')) {
            if ($employee->photo_path) {
                Storage::disk(self::PHOTO_DISK)->delete($employee->photo_path);
            }
            $validated['photo_path'] = $request->file('photo')->store('badges', self::PHOTO_DISK);
        } elseif ($request->boolean('remove_photo') && $employee->photo_path) {
            Storage::disk(self::PHOTO_DISK)->delete($employee->photo_path);
            $validated['photo_path'] = null;
        }
        unset($validated['photo'], $validated['remove_photo']);

        if ($request->hasFile('cin_photo')) {
            if ($employee->cin_photo_path) {
                Storage::disk(self::PHOTO_DISK)->delete($employee->cin_photo_path);
            }
            $validated['cin_photo_path'] = $request->file('cin_photo')->store('cin-cards', self::PHOTO_DISK);
        } elseif ($request->boolean('remove_cin_photo') && $employee->cin_photo_path) {
            Storage::disk(self::PHOTO_DISK)->delete($employee->cin_photo_path);
            $validated['cin_photo_path'] = null;
        }
        unset($validated['cin_photo'], $validated['remove_cin_photo']);

        $employee->update($validated);

        // Keep this employee's still-open pointage in sync with the new complement — otherwise
        // their stored net/brut stay priced at whatever complement was in effect when each cell
        // was entered, silently diverging from what a payslip would compute for the same day.
        if ($employee->wasChanged(['salaire_mensuel', 'enterprise_id', 'is_active'])) {
            // Moves/creates/removes the salary record and reprices the days (recalculation included).
            app(SalaireFixeService::class)->syncEmployee($employee);
        } elseif ($employee->wasChanged('complement')) {
            $this->payrollService->recalculateOpenRecordsForEmployee($employee);
        }

        return redirect()->back();
    }

    public function toggleActive(Request $request, Employee $employee)
    {
        $this->assertEmployeeManagerAccess($request, $employee);

        // Switched by hand: no longer tied to their équipe's own on/off (EquipeController).
        $employee->update(['is_active' => !$employee->is_active, 'desactive_avec_equipe' => false]);
        if ($employee->salaire_mensuel !== null) {
            app(SalaireFixeService::class)->syncEmployee($employee);
        }
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

        $employee->load(['enterprise', 'residenceLocation', 'equipe.chef:id,full_name', 'equipe.transportVehicle.transportCompany']);

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
            'enterprises' => $this->selectableEnterprises($request),
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
    /**
     * Streams an employee's badge photo (`photo`) or CIN scan (`cin`) from the private disk —
     * the only way either file is reachable (see PHOTO_DISK). Same access boundary as show():
     * the employee must be in the requester's farm, and division, if they're locked to one.
     */
    public function photo(Request $request, Employee $employee, string $kind)
    {
        $this->assertEmployeeInScope($request, $employee);

        $path = $kind === 'cin' ? $employee->cin_photo_path : $employee->photo_path;
        abort_unless($path && Storage::disk(self::PHOTO_DISK)->exists($path), 404);

        // no-store: an ID card must not linger in a shared browser's cache, and it keeps a just
        // replaced photo from showing the old one under the same URL.
        return Storage::disk(self::PHOTO_DISK)->response($path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

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
        $this->assertEmployeeManagerAccess($request, $employee);

        // pointage_records.employee_id is restrict (migration 000042) — deleting an employee
        // used to cascade away every pointage row they ever had, closed quinzaines included.
        // Someone who has worked is deactivated instead; only a never-pointed record (e.g. a
        // data-entry mistake) can actually be deleted.
        if (PointageRecord::where('employee_id', $employee->id)->exists()) {
            return redirect()->back()->with(
                'error',
                'Ce salarié a des pointages enregistrés et ne peut pas être supprimé. Désactivez-le plutôt.'
            );
        }

        $employee->delete();
        // Their ID card and badge photo would otherwise stay on disk with nothing pointing at them.
        Storage::disk(self::PHOTO_DISK)->delete(array_filter([$employee->photo_path, $employee->cin_photo_path]));
        // Not redirect()->back(): when deletion is triggered from the employee's own Show page
        // (see EmployeeFormModal usage there), "back" would just redirect to that same
        // now-404'd URL. The list is the one place always safe to land on regardless of which
        // page the delete came from.
        return redirect()->route('employees.index');
    }
}
