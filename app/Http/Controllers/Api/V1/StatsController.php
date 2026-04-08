<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyStat;
use App\Models\Gene;
use App\Models\Reclassification;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function index(): JsonResponse
    {
        $latestStat = DailyStat::latest('date')->first();

        return response()->json([
            'data' => [
                'total_genes' => Gene::count(),
                'total_variants' => Variant::count(),
                'total_vus' => Variant::where('classification', 'uncertain_significance')->count(),
                'total_reclassifications' => Reclassification::count(),
                'last_updated' => $latestStat?->date,
            ],
        ]);
    }
}
