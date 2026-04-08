<?php

namespace App\Console\Commands;

use App\Models\Condition;
use App\Models\Gene;
use App\Models\Variant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportClinvarVcf extends Command
{
    protected $signature = 'vus:import-vcf {file : Path to clinvar.vcf or clinvar.vcf.gz}';
    protected $description = 'Import ClinVar VCF file into the database';

    private array $classificationMap = [
        'Pathogenic' => 'pathogenic',
        'Pathogenic/Likely_pathogenic' => 'pathogenic',
        'Likely_pathogenic' => 'likely_pathogenic',
        'Uncertain_significance' => 'uncertain_significance',
        'Likely_benign' => 'likely_benign',
        'Benign' => 'benign',
        'Benign/Likely_benign' => 'benign',
        'Conflicting_classifications_of_pathogenicity' => 'conflicting',
        'Conflicting_interpretations_of_pathogenicity' => 'conflicting',
        'not_provided' => 'not_provided',
        'drug_response' => 'not_provided',
        'risk_factor' => 'not_provided',
        'association' => 'not_provided',
        'protective' => 'not_provided',
        'Affects' => 'not_provided',
        'other' => 'not_provided',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');
        if (!file_exists($file)) {
            $this->error("File not found: $file");
            return 1;
        }

        $this->info("Importing ClinVar VCF: $file");

        // Open file (support .gz)
        $isGz = str_ends_with($file, '.gz');
        $fh = $isGz ? gzopen($file, 'r') : fopen($file, 'r');
        if (!$fh) {
            $this->error("Cannot open file");
            return 1;
        }

        $geneCache = [];
        $conditionCache = [];
        $batch = [];
        $processed = 0;
        $skipped = 0;
        $batchSize = 500;

        $bar = $this->output->createProgressBar();
        $bar->start();

        while (($line = $isGz ? gzgets($fh) : fgets($fh)) !== false) {
            $line = trim($line);
            if (str_starts_with($line, '#')) continue;

            $cols = explode("\t", $line);
            if (count($cols) < 8) { $skipped++; continue; }

            [$chr, $pos, $id, $ref, $alt, $qual, $filter, $infoStr] = $cols;

            // Parse INFO field
            $info = [];
            foreach (explode(';', $infoStr) as $kv) {
                $parts = explode('=', $kv, 2);
                $info[$parts[0]] = $parts[1] ?? '';
            }

            // Extract gene symbol from GENEINFO
            $geneSymbol = null;
            if (!empty($info['GENEINFO'])) {
                $geneParts = explode(':', $info['GENEINFO']);
                $geneSymbol = $geneParts[0] ?? null;
                // Handle multi-gene: take first
                if ($geneSymbol && str_contains($geneSymbol, '|')) {
                    $geneSymbol = explode('|', $geneSymbol)[0];
                }
            }

            if (!$geneSymbol || strlen($geneSymbol) > 50) { $skipped++; continue; }

            // Get or cache gene
            if (!isset($geneCache[$geneSymbol])) {
                $geneCache[$geneSymbol] = Gene::firstOrCreate(['symbol' => $geneSymbol])->id;
            }
            $geneId = $geneCache[$geneSymbol];

            // Classification
            $clnsig = $info['CLNSIG'] ?? 'not_provided';
            $classification = $this->classificationMap[$clnsig] ?? 'not_provided';

            // HGVS
            $hgvs = $info['CLNHGVS'] ?? "chr{$chr}:{$pos}:{$ref}>{$alt}";
            $hgvs = mb_substr($hgvs, 0, 500);

            // Review status
            $reviewStatus = str_replace('_', ' ', $info['CLNREVSTAT'] ?? '');

            // Condition
            $conditionName = str_replace('_', ' ', $info['CLNDN'] ?? '');
            if ($conditionName === 'not provided' || $conditionName === 'not specified') {
                $conditionName = null;
            }

            // Phenotype IDs from CLNDISDB
            $phenotypeIds = null;
            if (!empty($info['CLNDISDB'])) {
                $phenotypeIds = $this->parsePhenotypeIds($info['CLNDISDB']);
            }

            $batch[] = [
                'gene_id' => $geneId,
                'variation_id' => !empty($id) && $id !== '.' ? (int) $id : null,
                'hgvs' => $hgvs,
                'classification' => $classification,
                'review_status' => mb_substr($reviewStatus, 0, 100),
                'submitter' => null, // VCF doesn't include submitter per-record
                'date_last_evaluated' => null,
                'chromosome' => $chr,
                'position' => (int) $pos,
                'ref_allele' => mb_substr($ref, 0, 1000),
                'alt_allele' => mb_substr($alt, 0, 1000),
                'rcv_accession' => null,
                'condition' => $conditionName ? mb_substr($conditionName, 0, 500) : null,
                'phenotype_ids' => $phenotypeIds ? json_encode($phenotypeIds) : null,
                'origin' => $info['ORIGIN'] ?? null,
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

        // Insert remaining
        if (!empty($batch)) {
            $this->insertBatch($batch);
            $processed += count($batch);
        }

        $isGz ? gzclose($fh) : fclose($fh);

        $bar->finish();
        $this->newLine();
        $this->info("Imported: $processed variants, Skipped: $skipped");

        // Handle conditions
        $this->info("Linking conditions to genes...");
        $this->linkConditions();

        // Recompute gene counts
        $this->info("Recomputing gene counts...");
        DB::statement("
            UPDATE genes g SET
                total_variants = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id),
                pathogenic_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'pathogenic'),
                likely_pathogenic_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'likely_pathogenic'),
                vus_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'uncertain_significance'),
                likely_benign_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'likely_benign'),
                benign_count = (SELECT COUNT(*) FROM variants WHERE gene_id = g.id AND classification = 'benign')
        ");

        $this->info("Done!");
        return 0;
    }

    private function insertBatch(array $batch): void
    {
        try {
            DB::table('variants')->insert($batch);
        } catch (\Throwable $e) {
            // Fallback: insert one by one
            foreach ($batch as $row) {
                try {
                    DB::table('variants')->insert($row);
                } catch (\Throwable $e2) {
                    // Skip problematic rows
                }
            }
        }
    }

    private function parsePhenotypeIds(string $disdb): ?array
    {
        $result = [];
        foreach (explode('|', $disdb) as $entry) {
            foreach (explode(',', $entry) as $item) {
                if (str_starts_with($item, 'OMIM:')) {
                    $result['omim'][] = substr($item, 5);
                } elseif (str_starts_with($item, 'MedGen:')) {
                    $result['medgen'][] = substr($item, 7);
                } elseif (str_starts_with($item, 'Orphanet:')) {
                    $result['orphanet'][] = substr($item, 9);
                }
            }
        }
        return !empty($result) ? $result : null;
    }

    private function linkConditions(): void
    {
        $variants = DB::table('variants')
            ->select('gene_id', 'condition')
            ->whereNotNull('condition')
            ->where('condition', '!=', '')
            ->groupBy('gene_id', 'condition')
            ->get();

        foreach ($variants as $v) {
            $condition = Condition::firstOrCreate(
                ['name' => mb_substr($v->condition, 0, 500)],
            );
            DB::table('condition_gene')->insertOrIgnore([
                'condition_id' => $condition->id,
                'gene_id' => $v->gene_id,
            ]);
        }
    }
}
