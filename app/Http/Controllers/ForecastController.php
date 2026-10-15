<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Services\ForecastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ForecastController extends Controller
{
    /**
     * Show the Forecast & DSS page.
     * Handles GET (display) and POST (save recommendations).
     */
    public function index(Request $request, ForecastService $forecastService)
    {
        $user = Auth::user();

        // Get branches for the filter dropdown
        if ($user->isBranchScoped()) {
            $branches = Branch::where('id', $user->branch_id)
                ->where('is_active', true)
                ->get();
            $selBranch = $user->branch_id;
        } else {
            $branches = Branch::where('is_active', true)
                ->orderBy('branch_name')
                ->get();
            $selBranch = (int) $request->input('branch', 0);
        }

        // Handle save action
        if ($request->isMethod('post') && $request->input('action') === 'save') {
            $forecasts = $forecastService->buildForecast($selBranch ?: null);
            $count = $forecastService->saveForecast($forecasts);

            return redirect()->route('forecast', ['branch' => $selBranch])
                ->with('success', "Forecast saved: {$count} recommendation(s) stored for next week.");
        }

        // Build forecast
        $forecasts = $forecastService->buildForecast($selBranch ?: null);

        // Trend badge mapping
        $trendBadges = [
            'rising' => ['hv-badge-success',   'bi-graph-up-arrow',   'Rising'],
            'falling' => ['hv-badge-danger',    'bi-graph-down-arrow', 'Falling'],
            'stable' => ['hv-badge-info',      'bi-dash-lg',          'Stable'],
            'flat' => ['hv-badge-warning',   'bi-dash',             'No Activity'],
            'insufficient' => ['hv-badge-secondary', 'bi-question-circle',  'Insufficient Data'],
        ];

        // Stats for summary cards
        $needsReorder = count(array_filter($forecasts, fn ($f) => $f['recommend_qty'] > 0));
        $totalUnits = array_sum(array_column($forecasts, 'recommend_qty'));

        $mainBranch = Branch::where('branch_type', 'main')->where('is_active', true)->orderBy('id')->first();
        $allowAllBranches = ! $user->isBranchScoped();

        return view('forecast.index', compact(
            'forecasts',
            'branches',
            'selBranch',
            'trendBadges',
            'needsReorder',
            'totalUnits',
            'mainBranch',
            'allowAllBranches'
        ));
    }
}
