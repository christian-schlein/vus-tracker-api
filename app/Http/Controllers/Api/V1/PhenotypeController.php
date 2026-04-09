<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhenotypeController extends Controller
{
    /**
     * GET /phenotype/search?q=telecanthus
     * Fuzzy search HPO terms, returns matching terms with gene counts.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim($request->query('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['data' => [], 'message' => 'Query must be at least 2 characters.'], 422);
        }

        // Try FULLTEXT first
        $results = DB::table('hpo_terms')
            ->selectRaw('hpo_terms.id, hpo_terms.hpo_id, hpo_terms.name, COUNT(hpo_gene.gene_symbol) as gene_count, MATCH(hpo_terms.name) AGAINST(? IN BOOLEAN MODE) as relevance', [$q . '*'])
            ->leftJoin('hpo_gene', 'hpo_terms.id', '=', 'hpo_gene.hpo_term_id')
            ->whereRaw('MATCH(hpo_terms.name) AGAINST(? IN BOOLEAN MODE)', [$q . '*'])
            ->groupBy('hpo_terms.id', 'hpo_terms.hpo_id', 'hpo_terms.name')
            ->orderByDesc('relevance')
            ->orderByDesc('gene_count')
            ->limit(20)
            ->get();

        // Fallback: LIKE + SOUNDEX
        if ($results->isEmpty()) {
            $results = DB::table('hpo_terms')
                ->select('hpo_terms.id', 'hpo_terms.hpo_id', 'hpo_terms.name')
                ->selectRaw('COUNT(hpo_gene.gene_symbol) as gene_count')
                ->leftJoin('hpo_gene', 'hpo_terms.id', '=', 'hpo_gene.hpo_term_id')
                ->where(function ($query) use ($q) {
                    $query->where('hpo_terms.name', 'LIKE', '%' . $q . '%')
                        ->orWhereRaw('SOUNDEX(hpo_terms.name) = SOUNDEX(?)', [$q]);
                })
                ->groupBy('hpo_terms.id', 'hpo_terms.hpo_id', 'hpo_terms.name')
                ->orderByDesc('gene_count')
                ->limit(20)
                ->get();
        }

        return response()->json([
            'data' => $results->map(fn ($row) => [
                'hpo_id'     => $row->hpo_id,
                'name'       => $row->name,
                'gene_count' => (int) $row->gene_count,
            ]),
        ]);
    }

    /**
     * GET /phenotype/genes?hpo=HP:0000316,HP:0000508
     * Given HPO IDs, return ranked gene list by overlap.
     */
    public function genes(Request $request): JsonResponse
    {
        $hpoParam = $request->query('hpo', '');
        $hpoIds = array_filter(array_map('trim', explode(',', $hpoParam)));

        if (empty($hpoIds)) {
            return response()->json(['data' => [], 'message' => 'Provide at least one HPO ID.'], 422);
        }

        $totalTerms = count($hpoIds);

        // Get term IDs and names
        $terms = DB::table('hpo_terms')
            ->whereIn('hpo_id', $hpoIds)
            ->pluck('name', 'id')
            ->toArray();

        $termIds = array_keys($terms);

        if (empty($termIds)) {
            return response()->json(['data' => [], 'message' => 'No matching HPO terms found.']);
        }

        // Get genes ranked by how many of the given HPO terms they appear in
        $geneRows = DB::table('hpo_gene')
            ->select('gene_symbol', 'gene_id')
            ->selectRaw('COUNT(DISTINCT hpo_term_id) as match_count')
            ->selectRaw('GROUP_CONCAT(DISTINCT hpo_term_id) as matched_term_ids')
            ->selectRaw('GROUP_CONCAT(DISTINCT disease_id) as diseases')
            ->whereIn('hpo_term_id', $termIds)
            ->groupBy('gene_symbol', 'gene_id')
            ->orderByDesc('match_count')
            ->orderBy('gene_symbol')
            ->limit(200)
            ->get();

        // Get variant counts from genes table
        $symbols = $geneRows->pluck('gene_symbol')->toArray();
        $variantCounts = DB::table('genes')
            ->whereIn('symbol', $symbols)
            ->pluck('total_variants', 'symbol')
            ->toArray();

        $data = $geneRows->map(function ($row) use ($terms, $totalTerms, $variantCounts) {
            $matchedTermIds = array_filter(explode(',', $row->matched_term_ids));
            $hpoMatches = array_values(array_map(
                fn ($id) => $terms[(int) $id] ?? null,
                $matchedTermIds
            ));
            $hpoMatches = array_filter($hpoMatches);

            $diseases = $row->diseases
                ? array_values(array_unique(array_filter(explode(',', $row->diseases))))
                : [];

            return [
                'gene_symbol'    => $row->gene_symbol,
                'match_count'    => (int) $row->match_count,
                'total_hpo_terms' => $totalTerms,
                'hpo_matches'    => array_values($hpoMatches),
                'diseases'       => $diseases,
                'variant_count'  => (int) ($variantCounts[$row->gene_symbol] ?? 0),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /phenotype/term/{hpoId}
     * Detail for one HPO term: all associated genes + diseases.
     */
    public function term(string $hpoId): JsonResponse
    {
        $term = DB::table('hpo_terms')->where('hpo_id', $hpoId)->first();

        if (! $term) {
            return response()->json(['message' => 'HPO term not found.'], 404);
        }

        $associations = DB::table('hpo_gene')
            ->where('hpo_term_id', $term->id)
            ->select('gene_symbol', 'gene_id', 'disease_id')
            ->orderBy('gene_symbol')
            ->get();

        // Get variant counts
        $symbols = $associations->pluck('gene_symbol')->unique()->toArray();
        $variantCounts = DB::table('genes')
            ->whereIn('symbol', $symbols)
            ->pluck('total_variants', 'symbol')
            ->toArray();

        // Group by gene
        $genes = $associations->groupBy('gene_symbol')->map(function ($rows, $symbol) use ($variantCounts) {
            return [
                'gene_symbol'   => $symbol,
                'diseases'      => $rows->pluck('disease_id')->filter()->unique()->values(),
                'variant_count' => (int) ($variantCounts[$symbol] ?? 0),
            ];
        })->values();

        return response()->json([
            'data' => [
                'hpo_id'     => $term->hpo_id,
                'name'       => $term->name,
                'gene_count' => $genes->count(),
                'genes'      => $genes,
            ],
        ]);
    }
}
