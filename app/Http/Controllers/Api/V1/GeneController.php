<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gene;
use App\Models\Reclassification;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Gene::query();

        if ($request->filled('q')) {
            $query->where('symbol', 'LIKE', '%' . $request->input('q') . '%');
        }

        $sort = $request->input('sort', 'vus_count');
        $order = $request->input('order', 'desc');
        $allowed = ['symbol', 'total_variants', 'vus_count', 'pathogenic_count'];
        if (in_array($sort, $allowed, true)) {
            $query->orderBy($sort, $order === 'asc' ? 'asc' : 'desc');
        }

        // If date range given, compute counts from variants table instead
        if ($request->filled('date_from') || $request->filled('date_to')) {
            return $this->indexWithDateFilter($request);
        }

        $genes = $query->paginate($request->integer('per_page', 50));

        return response()->json($genes);
    }

    private function indexWithDateFilter(Request $request): JsonResponse
    {
        $query = Variant::query()
            ->select('gene_id', DB::raw("
                COUNT(*) as total_variants,
                SUM(classification = 'pathogenic') as pathogenic_count,
                SUM(classification = 'likely_pathogenic') as likely_pathogenic_count,
                SUM(classification = 'uncertain_significance') as vus_count,
                SUM(classification = 'likely_benign') as likely_benign_count,
                SUM(classification = 'benign') as benign_count
            "))
            ->groupBy('gene_id');

        if ($request->filled('date_from')) {
            $query->where('date_last_evaluated', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date_last_evaluated', '<=', $request->input('date_to'));
        }

        $sub = $query->toBase();
        $genes = DB::table(DB::raw("({$sub->toSql()}) as variant_counts"))
            ->mergeBindings($sub)
            ->join('genes', 'genes.id', '=', 'variant_counts.gene_id')
            ->select('genes.symbol', 'genes.full_name', 'genes.omim_id',
                'variant_counts.total_variants', 'variant_counts.pathogenic_count',
                'variant_counts.likely_pathogenic_count', 'variant_counts.vus_count',
                'variant_counts.likely_benign_count', 'variant_counts.benign_count')
            ->orderByDesc('variant_counts.vus_count')
            ->paginate($request->integer('per_page', 50));

        return response()->json($genes);
    }

    public function show(string $symbol): JsonResponse
    {
        $gene = Gene::where('symbol', $symbol)->firstOrFail();
        $conditions = $gene->conditions()->pluck('name');

        return response()->json([
            'data' => [
                'symbol' => $gene->symbol,
                'full_name' => $gene->full_name,
                'omim_id' => $gene->omim_id,
                'medgen_id' => $gene->medgen_id,
                'orphanet_id' => $gene->orphanet_id,
                'hgnc_id' => $gene->hgnc_id,
                'classification_counts' => [
                    'pathogenic' => $gene->pathogenic_count,
                    'likely_pathogenic' => $gene->likely_pathogenic_count,
                    'uncertain_significance' => $gene->vus_count,
                    'likely_benign' => $gene->likely_benign_count,
                    'benign' => $gene->benign_count,
                ],
                'total_variants' => $gene->total_variants,
                'conditions' => $conditions,
                'updated_at' => $gene->updated_at,
            ],
        ]);
    }

    public function variants(Request $request, string $symbol): JsonResponse
    {
        $gene = Gene::where('symbol', $symbol)->firstOrFail();
        $query = $gene->variants();

        if ($request->filled('classification')) {
            $classes = explode(',', $request->input('classification'));
            $query->whereIn('classification', $classes);
        }
        if ($request->filled('date_from')) {
            $query->where('date_last_evaluated', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date_last_evaluated', '<=', $request->input('date_to'));
        }
        if ($request->filled('submitter')) {
            $query->where('submitter', 'LIKE', '%' . $request->input('submitter') . '%');
        }
        if ($request->filled('condition')) {
            $query->where('condition', 'LIKE', '%' . $request->input('condition') . '%');
        }

        $variants = $query->orderByDesc('date_last_evaluated')
            ->paginate($request->integer('per_page', 50));

        return response()->json($variants);
    }

    public function reclassifications(Request $request, string $symbol): JsonResponse
    {
        $gene = Gene::where('symbol', $symbol)->firstOrFail();
        $query = $gene->reclassifications()->orderByDesc('reclassified_at');

        if ($request->filled('date_from')) {
            $query->where('reclassified_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('reclassified_at', '<=', $request->input('date_to'));
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function timeline(Request $request, string $symbol): JsonResponse
    {
        $gene = Gene::where('symbol', $symbol)->firstOrFail();

        $buckets = $gene->variants()
            ->select(
                DB::raw("DATE_FORMAT(date_last_evaluated, '%Y-%m') as month"),
                DB::raw("SUM(classification = 'pathogenic') as pathogenic"),
                DB::raw("SUM(classification = 'likely_pathogenic') as likely_pathogenic"),
                DB::raw("SUM(classification = 'uncertain_significance') as vus"),
                DB::raw("SUM(classification = 'likely_benign') as likely_benign"),
                DB::raw("SUM(classification = 'benign') as benign"),
                DB::raw("COUNT(*) as total"),
            )
            ->whereNotNull('date_last_evaluated')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'data' => ['gene' => $symbol, 'buckets' => $buckets],
        ]);
    }

    public function concordance(string $symbol): JsonResponse
    {
        $gene = Gene::where('symbol', $symbol)->firstOrFail();

        // Get variants with multiple submitters having different classifications
        $variants = $gene->variants()
            ->select('hgvs', 'classification', 'submitter')
            ->whereNotNull('submitter')
            ->orderBy('hgvs')
            ->get()
            ->groupBy('hgvs');

        $matrix = [];
        $concordant = 0;
        $discordant = 0;

        foreach ($variants as $hgvs => $submissions) {
            if ($submissions->count() < 2) continue;

            $classifications = $submissions->pluck('classification', 'submitter')->toArray();
            $unique = array_unique($classifications);
            $isDiscordant = count($unique) > 1;

            if ($isDiscordant) $discordant++;
            else $concordant++;

            $matrix[] = [
                'hgvs' => $hgvs,
                'classifications' => $classifications,
                'concordant' => !$isDiscordant,
            ];
        }

        $total = $concordant + $discordant;

        return response()->json([
            'data' => [
                'gene' => $symbol,
                'concordance_rate' => $total > 0 ? round($concordant / $total, 3) : null,
                'concordant' => $concordant,
                'discordant' => $discordant,
                'matrix' => array_slice($matrix, 0, 200), // limit response size
            ],
        ]);
    }

    public function survival(string $symbol): JsonResponse
    {
        $gene = Gene::where('symbol', $symbol)->firstOrFail();

        // VUS survival: how long variants stay as VUS before reclassification
        $reclass = Reclassification::where('gene_id', $gene->id)
            ->where('old_classification', 'uncertain_significance')
            ->whereIn('new_classification', ['pathogenic', 'likely_pathogenic', 'benign', 'likely_benign'])
            ->join('variants', 'reclassifications.variant_id', '=', 'variants.id')
            ->select(
                DB::raw("DATEDIFF(reclassifications.reclassified_at, variants.date_last_evaluated) as days_as_vus"),
            )
            ->whereNotNull('variants.date_last_evaluated')
            ->where('variants.date_last_evaluated', '>', '2000-01-01')
            ->orderBy('days_as_vus')
            ->pluck('days_as_vus')
            ->filter(fn($d) => $d > 0)
            ->values();

        $totalVus = $gene->vus_count;
        $totalResolved = $reclass->count();

        // Build Kaplan-Meier style curve
        $points = [['months' => 0, 'fraction' => 1.0]];
        if ($totalVus > 0 && $totalResolved > 0) {
            $remaining = $totalVus;
            $monthBuckets = $reclass->groupBy(fn($d) => intdiv($d, 30));

            foreach ($monthBuckets->sortKeys() as $month => $events) {
                $remaining -= $events->count();
                $points[] = [
                    'months' => (int) $month,
                    'fraction' => round(max(0, $remaining / $totalVus), 4),
                ];
            }
        }

        return response()->json([
            'data' => [
                'gene' => $symbol,
                'total_vus' => $totalVus,
                'total_resolved' => $totalResolved,
                'points' => $points,
            ],
        ]);
    }

    public function drift(Request $request, string $symbol): JsonResponse
    {
        $gene = Gene::where('symbol', $symbol)->firstOrFail();

        // Classification distribution over yearly snapshots
        $drift = $gene->variants()
            ->select(
                DB::raw("YEAR(date_last_evaluated) as year"),
                DB::raw("SUM(classification = 'pathogenic') as pathogenic"),
                DB::raw("SUM(classification = 'likely_pathogenic') as likely_pathogenic"),
                DB::raw("SUM(classification = 'uncertain_significance') as vus"),
                DB::raw("SUM(classification = 'likely_benign') as likely_benign"),
                DB::raw("SUM(classification = 'benign') as benign"),
                DB::raw("COUNT(*) as total"),
            )
            ->whereNotNull('date_last_evaluated')
            ->where('date_last_evaluated', '>', '2000-01-01')
            ->groupBy('year')
            ->orderBy('year')
            ->get();

        return response()->json([
            'data' => ['gene' => $symbol, 'snapshots' => $drift],
        ]);
    }

    public function genomeBrowser(string $symbol): JsonResponse
    {
        $gene = Gene::where('symbol', $symbol)->firstOrFail();

        $variants = $gene->variants()
            ->whereNotNull('chromosome')
            ->whereNotNull('position')
            ->select('id', 'hgvs', 'classification', 'chromosome', 'position',
                'ref_allele', 'alt_allele', 'submitter', 'condition')
            ->orderBy('position')
            ->limit(5000)
            ->get();

        return response()->json([
            'data' => [
                'gene' => $symbol,
                'assembly' => 'GRCh38',
                'variants' => $variants,
            ],
        ]);
    }
}
