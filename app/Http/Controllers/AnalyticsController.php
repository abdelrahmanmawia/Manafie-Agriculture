<?php

namespace App\Http\Controllers;

use App\Exports\AnalyticsExport;
use App\Models\Bloc;
use App\Models\Enterprise;
use App\Models\Farm;
use App\Models\Sector;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class AnalyticsController extends Controller
{
    private const FILTRES = ['bloc_id', 'sector_id', 'quinzaine_id', 'quinzaine_from', 'quinzaine_to'];

    public function index(Request $request, AnalyticsService $analytics)
    {
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);
        $donnees = $analytics->calculer($farmId, $enterpriseId, $request->only(self::FILTRES));
        unset($donnees['operations']); // The export's full list; the page charts the top 10.

        return Inertia::render('Admin/Analytics', $donnees + [
            'selectedQuinzaineId' => $request->query('quinzaine_id'),
            'quinzaineFromId' => $request->query('quinzaine_from'),
            'quinzaineToId' => $request->query('quinzaine_to'),
            'blocId' => $request->query('bloc_id'),
            'sectorId' => $request->query('sector_id'),
            'enterprise' => $enterpriseId ? Enterprise::find($enterpriseId) : null,
            'enterprises' => $this->selectableEnterprises($request),
            'blocs' => Bloc::where('farm_id', $farmId)->get(),
            'sectors' => Sector::whereHas('bloc', fn ($q) => $q->where('farm_id', $farmId))->get(),
        ]);
    }

    /** The same figures for the same filters, as a workbook. */
    public function export(Request $request, AnalyticsService $analytics)
    {
        $farmId = $this->scopedFarmId($request);
        $enterpriseId = $this->scopedEnterpriseId($request, $farmId);
        $donnees = $analytics->calculer($farmId, $enterpriseId, $request->only(self::FILTRES));
        $bloc = $request->query('bloc_id') ? Bloc::where('farm_id', $farmId)->find($request->query('bloc_id')) : null;
        $sector = $request->query('sector_id') ? Sector::whereHas('bloc', fn ($q) => $q->where('farm_id', $farmId))->find($request->query('sector_id')) : null;

        return Excel::download(new AnalyticsExport($donnees, array_filter([
            'Ferme' => Farm::find($farmId)?->name,
            'Division' => $enterpriseId ? Enterprise::find($enterpriseId)?->name : 'Toutes',
            'Bloc' => $bloc?->name,
            'Secteur' => $sector?->name,
        ])), 'Analyses_'.now()->format('Y-m-d').'.xlsx');
    }
}
