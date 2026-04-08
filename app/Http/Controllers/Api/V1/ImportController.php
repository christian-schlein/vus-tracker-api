<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Gene;
use App\Models\ImportRun;
use App\Models\Reclassification;
use App\Models\Variant;
use App\Models\Condition;
use App\Models\DailyStat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    public function variants(Request $request): JsonResponse
    {
        $run = ImportRun::create(['source' => 'github_actions', 'status' => 'running']);
        $processed = 0;
        $errors = 0;
        $errorLog = [];

        $content = $request->getContent();
        $lines = preg_split('/\R/', $content, -1, PREG_SPLIT_NO_EMPTY);

        $batch = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!$row) {
                $errors++;
                continue;
            }

            try {
                $geneSymbol = $row['gene'] ?? $row['gene_symbol'] ?? null;
                if (!$geneSymbol) {
                    $errors++;
                    continue;
                }

                $gene = Gene::firstOrCreate(
                    ['symbol' => $geneSymbol],
                    ['full_name' => $row['gene_name'] ?? null],
                );

                // Handle condition
                if (!empty($row['condition'])) {
                    $condition = Condition::firstOrCreate(['name' => $row['condition']]);
                    $gene->conditions()->syncWithoutDetaching([$condition->id]);
                }

                // Map classification
                $classification = $this->normalizeClassification($row['classification'] ?? $row['clinical_significance'] ?? 'not_provided');

                Variant::upsert([
                    [
                        'gene_id' => $gene->id,
                        'variation_id' => $row['variation_id'] ?? null,
                        'hgvs' => $row['hgvs'] ?? $row['name'] ?? 'unknown',
                        'classification' => $classification,
                        'review_status' => $row['review_status'] ?? null,
                        'submitter' => $row['submitter'] ?? $row['submitter_name'] ?? null,
                        'date_last_evaluated' => $row['date_last_evaluated'] ?? $row['last_evaluated'] ?? null,
                        'chromosome' => $row['chromosome'] ?? $row['chr'] ?? null,
                        'position' => $row['position'] ?? $row['pos'] ?? $row['start'] ?? null,
                        'ref_allele' => $row['ref'] ?? $row['ref_allele'] ?? null,
                        'alt_allele' => $row['alt'] ?? $row['alt_allele'] ?? null,
                        'rcv_accession' => $row['rcv_accession'] ?? $row['rcv'] ?? null,
                        'condition' => $row['condition'] ?? null,
                        'phenotype_ids' => isset($row['phenotype_ids']) ? json_encode($row['phenotype_ids']) : null,
                        'origin' => $row['origin'] ?? null,
                        'assembly' => $row['assembly'] ?? 'GRCh38',
                        'raw_json' => json_encode($row),
                        'clinvar_updated_at' => $row['clinvar_updated'] ?? now(),
                    ],
                ], ['variation_id', 'submitter', 'rcv_accession'], [
                    'classification', 'review_status', 'date_last_evaluated',
                    'chromosome', 'position', 'condition', 'phenotype_ids',
                    'raw_json', 'clinvar_updated_at',
                ]);

                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $errorLog[] = substr($e->getMessage(), 0, 200);
            }
        }

        $run->update([
            'variants_processed' => $processed,
            'errors' => $errors,
            'error_log' => $errors > 0 ? implode("\n", array_slice($errorLog, 0, 50)) : null,
        ]);

        return response()->json([
            'data' => [
                'import_run_id' => $run->id,
                'variants_processed' => $processed,
                'errors' => $errors,
            ],
        ]);
    }

    public function reclassifications(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $lines = preg_split('/\R/', $content, -1, PREG_SPLIT_NO_EMPTY);
        $processed = 0;

        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!$row) continue;

            $geneSymbol = $row['gene'] ?? $row['gene_symbol'] ?? null;
            if (!$geneSymbol) continue;

            $gene = Gene::firstOrCreate(['symbol' => $geneSymbol]);

            Reclassification::create([
                'gene_id' => $gene->id,
                'variation_id_clinvar' => $row['variation_id'] ?? null,
                'hgvs' => $row['hgvs'] ?? $row['name'] ?? 'unknown',
                'old_classification' => $this->normalizeClassification($row['old_classification'] ?? $row['old'] ?? ''),
                'new_classification' => $this->normalizeClassification($row['new_classification'] ?? $row['new'] ?? ''),
                'reclassified_at' => $row['reclassified_at'] ?? $row['date'] ?? now(),
                'submitter' => $row['submitter'] ?? null,
                'condition' => $row['condition'] ?? null,
            ]);
            $processed++;
        }

        return response()->json(['data' => ['reclassifications_processed' => $processed]]);
    }

    public function complete(): JsonResponse
    {
        // Recompute all gene aggregate counts
        DB::statement("
            UPDATE genes g SET
                total_variants = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id),
                pathogenic_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'pathogenic'),
                likely_pathogenic_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'likely_pathogenic'),
                vus_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'uncertain_significance'),
                likely_benign_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'likely_benign'),
                benign_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'benign')
        ");

        // Create daily stats snapshot
        DailyStat::updateOrCreate(
            ['date' => now()->toDateString()],
            [
                'total_variants' => Variant::count(),
                'total_genes' => Gene::count(),
                'total_vus' => Variant::where('classification', 'uncertain_significance')->count(),
                'total_reclassifications' => Reclassification::count(),
                'new_variants_today' => Variant::whereDate('created_at', today())->count(),
                'new_reclass_today' => Reclassification::whereDate('created_at', today())->count(),
            ],
        );

        // Mark latest running import as completed
        ImportRun::where('status', 'running')
            ->latest('started_at')
            ->update(['status' => 'completed', 'finished_at' => now()]);

        return response()->json(['data' => ['message' => 'Stats recomputed successfully']]);
    }

    private function normalizeClassification(string $raw): string
    {
        $map = [
            'pathogenic' => 'pathogenic',
            'likely pathogenic' => 'likely_pathogenic',
            'likely_pathogenic' => 'likely_pathogenic',
            'uncertain significance' => 'uncertain_significance',
            'uncertain_significance' => 'uncertain_significance',
            'vus' => 'uncertain_significance',
            'likely benign' => 'likely_benign',
            'likely_benign' => 'likely_benign',
            'benign' => 'benign',
            'conflicting' => 'conflicting',
            'conflicting interpretations' => 'conflicting',
            'conflicting_interpretations_of_pathogenicity' => 'conflicting',
        ];

        return $map[strtolower(trim($raw))] ?? 'not_provided';
    }
}
