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
        $query = Variant::query();
        $reclassQuery = Reclassification::query();

        if ($request->filled("date_from")) {
            $query->where("date_last_evaluated", ">=", $request->input("date_from"));
            $reclassQuery->where("reclassified_at", ">=", $request->input("date_from"));
        }
        if ($request->filled("date_to")) {
            $query->where("date_last_evaluated", "<=", $request->input("date_to"));
            $reclassQuery->where("reclassified_at", "<=", $request->input("date_to"));
        }

        $totalVariants = $query->count();
        $totalVus = (clone $query)->where("classification", "uncertain_significance")->count();
        $totalGenes = (clone $query)->distinct("gene_id")->count("gene_id");

        return response()->json([
            "data" => [
                "total_genes" => $totalGenes,
                "total_variants" => $totalVariants,
                "total_vus" => $totalVus,
                "total_reclassifications" => $reclassQuery->count(),
            ],
        ]);
    }

    public function submissionsTimeline(Request $request): JsonResponse
    {
        $query = Variant::query()->whereNotNull("date_last_evaluated");

        if ($request->filled("date_from")) {
            $query->where("date_last_evaluated", ">=", $request->input("date_from"));
        }
        if ($request->filled("date_to")) {
            $query->where("date_last_evaluated", "<=", $request->input("date_to"));
        }

        $buckets = $query->select(
            DB::raw("DATE(date_last_evaluated) as day"),
            DB::raw("SUM(classification IN (\"pathogenic\",\"likely_pathogenic\")) as path"),
            DB::raw("SUM(classification = \"uncertain_significance\") as vus"),
            DB::raw("SUM(classification IN (\"benign\",\"likely_benign\")) as ben"),
            DB::raw("COUNT(*) as total")
        )
        ->groupBy("day")
        ->orderBy("day")
        ->get();

        return response()->json(["data" => ["buckets" => $buckets]]);
    }
}
