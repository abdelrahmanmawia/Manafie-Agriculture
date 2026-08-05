<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class BadgeController extends Controller
{
    /**
     * Same farm-scoping shape as EmployeeController::index — Employee is farm-scoped, not
     * enterprise-scoped, so badges are printed per-farm regardless of which division an
     * employee currently belongs to.
     */
    private function scopedEmployees(Request $request)
    {
        $user = $request->user();
        $enterpriseId = $user->enterprise_id ?? $request->query('enterprise_id');

        $query = Employee::with('enterprise')->where('is_active', true);

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

        return $query->orderBy('full_name')->get();
    }

    public function index(Request $request)
    {
        return Inertia::render('Pointage/Badges', [
            'employees' => $this->scopedEmployees($request),
            'enterprises' => $request->user()->role === 'super_admin'
                ? \App\Models\Enterprise::where('farm_id', session('active_farm_id'))->get()
                : (($request->user()->role === 'farm_manager')
                    ? \App\Models\Enterprise::where('farm_id', $request->user()->farm_id)->get()
                    : []),
        ]);
    }

    /**
     * Prints badges for either the given employee_ids or, if none were selected, every
     * active employee currently in scope. Each employee's badge_uuid is generated once and
     * reused forever after — re-printing later must never invalidate badges already handed
     * out and glued to a lanyard.
     */
    public function print(Request $request)
    {
        $employeeIds = $request->query('employee_ids');
        // Was unscoped by farm entirely — any authenticated user could print another
        // farm's employee badges (names, matricules, QR codes) just by supplying its IDs.
        $employees = $employeeIds
            ? Employee::whereIn('id', explode(',', $employeeIds))
                ->where('farm_id', $this->scopedFarmId($request))
                ->get()
            : $this->scopedEmployees($request);

        foreach ($employees as $employee) {
            if (!$employee->badge_uuid) {
                $employee->badge_uuid = (string) Str::uuid();
                $employee->save();
            }
        }

        // Rendered as real PNGs (GD, no Imagick available on this box) rather than inline SVG —
        // this dompdf install doesn't render inline <svg> at all, confirmed even for a trivial
        // static one, so the QR would silently disappear from the PDF otherwise.
        $writer = new PngWriter();
        $qrDataUris = [];
        foreach ($employees as $employee) {
            $qr = QrCode::create($employee->badge_uuid)->setSize(200)->setMargin(0);
            $qrDataUris[$employee->id] = $writer->write($qr)->getDataUri();
        }

        $pdf = Pdf::loadView('badges.print', [
            'employees' => $employees,
            'qrDataUris' => $qrDataUris,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('Badges_Pointage.pdf');
    }
}
