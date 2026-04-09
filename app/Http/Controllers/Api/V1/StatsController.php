<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gene;
use App\Models\Reclassification;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->filled("date_from") || $request->filled("date_to")) {
            return $this->filteredStats($request);
        }

        // Fast path: use pre-computed gene counts
        $sums = Gene::selectRaw("
            COUNT(*) as gene_count,
            SUM(total_variants) as variant_sum,
            SUM(vus_count) as vus_sum
        ")->first();

        return response()->json([
            "data" => [
                "total_genes" => (int) ($sums->gene_count ?? 0),
                "total_variants" => (int) ($sums->variant_sum ?? 0),
                "total_vus" => (int) ($sums->vus_sum ?? 0),
                "total_reclassifications" => Reclassification::count(),
            ],
        ]);
    }

    private function filteredStats(Request $request): JsonResponse
    {
        $query = Variant::query();
        if ($request->filled("date_from")) $query->where("date_last_evaluated", ">=", $request->input("date_from"));
        if ($request->filled("date_to")) $query->where("date_last_evaluated", "<=", $request->input("date_to"));

        return response()->json([
            "data" => [
                "total_genes" => (clone $query)->distinct("gene_id")->count("gene_id"),
                "total_variants" => $query->count(),
                "total_vus" => (clone $query)->where("classification", "uncertain_significance")->count(),
                "total_reclassifications" => Reclassification::when($request->filled("date_from"), fn($q) => $q->where("reclassified_at", ">=", $request->input("date_from")))->count(),
            ],
        ]);
    }

    public function submissionsTimeline(Request $request): JsonResponse
    {
        $query = Variant::query()->whereNotNull("date_last_evaluated");
        if ($request->filled("date_from")) $query->where("date_last_evaluated", ">=", $request->input("date_from"));
        if ($request->filled("date_to")) $query->where("date_last_evaluated", "<=", $request->input("date_to"));

        $buckets = $query->select(
            DB::raw("DATE_FORMAT(date_last_evaluated, "%Y-%m") as month"),
            DB::raw("SUM(classification IN (\"pathogenic\",\"likely_pathogenic\")) as path"),
            DB::raw("SUM(classification = \"uncertain_significance\") as vus"),
            DB::raw("SUM(classification IN (\"benign\",\"likely_benign\")) as ben"),
            DB::raw("COUNT(*) as total")
        )
        ->groupBy("month")
        ->orderBy("month")
        
        ->get();

        return response()->json(["data" => ["buckets" => $buckets]]);
    }
}
