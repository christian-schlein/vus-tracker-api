<?php

namespace App\Console\Commands;

use App\Models\Condition;
use App\Models\Gene;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportVariantSummary extends Command
{
    protected $signature = 'vus:import-summary {file : Path to variant_summary.txt.gz} {--fresh : Truncate tables first}';
    protected $description = 'Import ClinVar variant_summary.txt.gz (complete dataset)';

    private array $classMap = [
        'Pathogenic' => 'pathogenic',
        'Pathogenic/Likely pathogenic' => 'pathogenic',
        'Likely pathogenic' => 'likely_pathogenic',
        'Uncertain significance' => 'uncertain_significance',
        'Uncertain risk allele' => 'uncertain_significance',
        'Likely benign' => 'likely_benign',
        'Benign' => 'benign',
        'Benign/Likely benign' => 'benign',
        'Conflicting classifications of pathogenicity' => 'conflicting',
        'Conflicting interpretations of pathogenicity' => 'conflicting',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');
        if (!file_exists($file)) { $this->error("Not found: $file"); return 1; }

        if ($this->option('fresh')) {
            $this->warn('Truncating all tables...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table('condition_gene')->truncate();
            DB::table('conditions')->truncate();
            DB::table('reclassifications')->truncate();
            DB::table('variants')->truncate();
            DB::table('genes')->truncate();
            DB::table('daily_stats')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info("Importing: $file");
        $fh = gzopen($file, 'r');
        $header = gzgets($fh); // skip header
        $cols = array_flip(array_map('trim', explode("\t", $header)));

        $geneCache = [];
        $batch = [];
        $processed = 0;
        $skipped = 0;
        $batchSize = 1000;

        $bar = $this->output->createProgressBar();
        $bar->start();

        while (($line = gzgets($fh)) !== false) {
            $fields = explode("\t", trim($line));
            if (count($fields) < 30) { $skipped++; continue; }

            $assembly = $fields[$cols['Assembly']] ?? '';
            if ($assembly !== 'GRCh38') { $skipped++; continue; } // Only GRCh38

            $geneSymbol = trim($fields[$cols['GeneSymbol']] ?? '');
            if (!$geneSymbol || $geneSymbol === '-' || strlen($geneSymbol) > 50) { $skipped++; continue; }

            $clinsig = $fields[$cols['ClinicalSignificance']] ?? '';
            $classification = $this->classMap[$clinsig] ?? 'not_provided';

            // Get or cache gene
            if (!isset($geneCache[$geneSymbol])) {
                $gene = Gene::firstOrCreate(
                    ['symbol' => $geneSymbol],
                    [
                        'full_name' => null,
                        'hgnc_id' => ($fields[$cols['HGNC_ID']] ?? '') !== '' ? $fields[$cols['HGNC_ID']] : null,
                    ],
                );
                $geneCache[$geneSymbol] = $gene->id;
            }

            $chr = $fields[$cols['Chromosome']] ?? '';
            $pos = $fields[$cols['Start']] ?? '';
            $lastEval = $fields[$cols['LastEvaluated']] ?? '';
            $phenoList = $fields[$cols['PhenotypeList']] ?? '';
            $phenoIds = $fields[$cols['PhenotypeIDS']] ?? '';
            $variationId = $fields[$cols['VariationID']] ?? '';
            $rcv = $fields[$cols['RCVaccession']] ?? '';
            $name = $fields[$cols['Name']] ?? '';
            $reviewStatus = $fields[$cols['ReviewStatus']] ?? '';
            $origin = $fields[$cols['OriginSimple']] ?? '';
            $ref = $fields[$cols['ReferenceAlleleVCF']] ?? '';
            $alt = $fields[$cols['AlternateAlleleVCF']] ?? '';
            $posVcf = $fields[$cols['PositionVCF']] ?? '';

            $batch[] = [
                'gene_id' => $geneCache[$geneSymbol],
                'variation_id' => $variationId !== '' ? (int) $variationId : null,
                'hgvs' => mb_substr($name, 0, 500),
                'classification' => $classification,
                'review_status' => mb_substr(str_replace('_', ' ', $reviewStatus), 0, 100),
                'submitter' => null,
                'date_last_evaluated' => ($lastEval && $lastEval !== '-') ? $this->parseDate($lastEval) : null,
                'chromosome' => ($chr && $chr !== '-') ? $chr : null,
                'position' => ($posVcf && $posVcf !== '-' && $posVcf !== '') ? (int) $posVcf : (($pos && $pos !== '-') ? (int) $pos : null),
                'ref_allele' => ($ref && $ref !== '-') ? mb_substr($ref, 0, 1000) : null,
                'alt_allele' => ($alt && $alt !== '-') ? mb_substr($alt, 0, 1000) : null,
                'rcv_accession' => ($rcv && $rcv !== '-') ? mb_substr(explode('|', $rcv)[0], 0, 50) : null,
                'condition' => ($phenoList && $phenoList !== '-') ? mb_substr(str_replace('|', '; ', $phenoList), 0, 500) : null,
                'phenotype_ids' => $this->parsePhenotypeIds($phenoIds),
                'origin' => ($origin && $origin !== '-') ? mb_substr($origin, 0, 50) : null,
                'assembly' => 'GRCh38',
                'raw_json' => null,
                'clinvar_updated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= $batchSize) {
                $this->insertBatch($batch);
                $processed += count($batch);
                $batch = [];
                $bar->setProgress($processed);
            }
        }

        if (!empty($batch)) {
            $this->insertBatch($batch);
            $processed += count($batch);
        }
        gzclose($fh);

        $bar->finish();
        $this->newLine();
        $this->info("Imported: $processed | Skipped: $skipped");

        $this->info('Linking conditions...');
        $this->linkConditions();

        $this->info('Recomputing gene counts...');
        DB::statement("
            UPDATE genes g SET
                total_variants = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id),
                pathogenic_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'pathogenic'),
                likely_pathogenic_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'likely_pathogenic'),
                vus_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'uncertain_significance'),
                likely_benign_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'likely_benign'),
                benign_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'benign')
        ");

        $this->info('Done!');
        return 0;
    }

    private function insertBatch(array $batch): void
    {
        try {
            DB::table('variants')->insert($batch);
        } catch (\Throwable $e) {
            foreach ($batch as $row) {
                try { DB::table('variants')->insert($row); } catch (\Throwable $e2) {}
            }
        }
    }

    private function parseDate(string $d): ?string
    {
        // ClinVar dates: "Jan 01, 2024" or "2024-01-01" or "01/2024"
        $d = trim($d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
        $t = strtotime($d);
        return $t ? date('Y-m-d', $t) : null;
    }

    private function parsePhenotypeIds(string $raw): ?string
    {
        if (!$raw || $raw === '-' || $raw === 'na') return null;
        $result = [];
        foreach (explode('|', $raw) as $entry) {
            foreach (explode(',', $entry) as $item) {
                $item = trim($item);
                if (str_starts_with($item, 'OMIM:')) $result['omim'][] = substr($item, 5);
                elseif (str_starts_with($item, 'MedGen:')) $result['medgen'][] = substr($item, 7);
                elseif (str_starts_with($item, 'Orphanet:')) $result['orphanet'][] = substr($item, 9);
                elseif (str_starts_with($item, 'Human Phenotype Ontology:')) $result['hpo'][] = substr($item, 25);
            }
        }
        return !empty($result) ? json_encode($result) : null;
    }

    private function linkConditions(): void
    {
        DB::table('variants')
            ->select('gene_id', 'condition')
            ->whereNotNull('condition')
            ->where('condition', '!=', '')
            ->groupBy('gene_id', 'condition')
            ->orderBy('gene_id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $v) {
                    try {
                        $cond = Condition::firstOrCreate(['name' => mb_substr($v->condition, 0, 500)]);
                        DB::table('condition_gene')->insertOrIgnore([
                            'condition_id' => $cond->id,
                            'gene_id' => $v->gene_id,
                        ]);
                    } catch (\Throwable $e) {}
                }
            });
    }
}
