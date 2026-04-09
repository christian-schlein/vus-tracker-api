<?php

namespace App\Console\Commands;

use App\Models\Gene;
use App\Models\HpoTerm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportHpo extends Command
{
    protected $signature = 'vus:import-hpo {file : Path to phenotype_to_genes.txt}';

    protected $description = 'Import HPO phenotype-to-gene mappings from HPO annotation file';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $this->info('Loading existing gene symbols...');
        $geneMap = Gene::pluck('id', 'symbol')->toArray();

        $this->info('Truncating existing HPO data...');
        DB::table('hpo_gene')->truncate();
        DB::table('hpo_terms')->truncate();

        $handle = fopen($file, 'r');
        if (! $handle) {
            $this->error("Cannot open file: {$file}");
            return 1;
        }

        $termCache = [];   // hpo_id => db_id
        $termBatch = [];
        $geneBatch = [];
        $lineCount = 0;
        $batchSize = 1000;

        $this->info('Importing HPO terms and gene associations...');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);

            // Skip comments and header
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cols = explode("\t", $line);
            if (count($cols) < 4) {
                continue;
            }

            $hpoId      = $cols[0];             // HP:0000316
            $hpoName    = $cols[1];             // Hypertelorism
            $ncbiGeneId = $cols[2] ?? null;     // 8200
            $geneSymbol = $cols[3] ?? null;     // GPC3
            $diseaseId  = $cols[4] ?? null;     // OMIM:312870

            // Insert HPO term if not seen yet
            if (! isset($termCache[$hpoId])) {
                $termBatch[] = [
                    'hpo_id' => $hpoId,
                    'name'   => $hpoName,
                ];
                $termCache[$hpoId] = true; // placeholder
            }

            // Flush term batch
            if (count($termBatch) >= $batchSize) {
                $this->flushTerms($termBatch, $termCache);
                $termBatch = [];
            }

            // Queue gene association
            if ($geneSymbol) {
                $geneBatch[] = [
                    'hpo_id'      => $hpoId,
                    'gene_symbol' => $geneSymbol,
                    'gene_id'     => $geneMap[$geneSymbol] ?? null,
                    'disease_id'  => $diseaseId,
                ];
            }

            // Flush gene batch
            if (count($geneBatch) >= $batchSize) {
                $this->flushTerms($termBatch, $termCache);
                $termBatch = [];
                $this->flushGenes($geneBatch, $termCache);
                $geneBatch = [];
            }

            $lineCount++;
            if ($lineCount % 50000 === 0) {
                $this->info("  Processed {$lineCount} lines...");
            }
        }

        fclose($handle);

        // Flush remaining
        if ($termBatch) {
            $this->flushTerms($termBatch, $termCache);
        }
        if ($geneBatch) {
            $this->flushGenes($geneBatch, $termCache);
        }

        $termCount = DB::table('hpo_terms')->count();
        $assocCount = DB::table('hpo_gene')->count();

        $this->info("Done. {$termCount} HPO terms, {$assocCount} gene associations from {$lineCount} lines.");

        return 0;
    }

    private function flushTerms(array &$batch, array &$cache): void
    {
        if (empty($batch)) {
            return;
        }

        DB::table('hpo_terms')->insertOrIgnore($batch);

        // Resolve IDs for all cached terms that are still placeholders
        $hpoIds = array_column($batch, 'hpo_id');
        $resolved = DB::table('hpo_terms')
            ->whereIn('hpo_id', $hpoIds)
            ->pluck('id', 'hpo_id')
            ->toArray();

        foreach ($resolved as $hpoId => $dbId) {
            $cache[$hpoId] = $dbId;
        }
    }

    private function flushGenes(array &$batch, array &$cache): void
    {
        if (empty($batch)) {
            return;
        }

        $rows = [];
        foreach ($batch as $entry) {
            $termId = $cache[$entry['hpo_id']] ?? null;
            if (! $termId || $termId === true) {
                // Term not yet resolved, resolve now
                $termId = DB::table('hpo_terms')
                    ->where('hpo_id', $entry['hpo_id'])
                    ->value('id');
                if ($termId) {
                    $cache[$entry['hpo_id']] = $termId;
                } else {
                    continue;
                }
            }

            $rows[] = [
                'hpo_term_id' => $termId,
                'gene_symbol' => $entry['gene_symbol'],
                'gene_id'     => $entry['gene_id'],
                'disease_id'  => $entry['disease_id'],
            ];
        }

        if ($rows) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('hpo_gene')->insert($chunk);
            }
        }
    }
}
