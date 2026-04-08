<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gene_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('variation_id')->nullable();
            $table->string('hgvs', 500);
            $table->enum('classification', [
                'pathogenic', 'likely_pathogenic', 'uncertain_significance',
                'likely_benign', 'benign', 'conflicting', 'not_provided',
            ]);
            $table->string('review_status', 100)->nullable();
            $table->string('submitter', 500)->nullable();
            $table->date('date_last_evaluated')->nullable()->index();
            $table->string('chromosome', 5)->nullable();
            $table->unsignedBigInteger('position')->nullable();
            $table->string('ref_allele', 1000)->nullable();
            $table->string('alt_allele', 1000)->nullable();
            $table->string('rcv_accession', 50)->nullable();
            $table->string('condition', 500)->nullable();
            $table->json('phenotype_ids')->nullable();
            $table->string('origin', 50)->nullable();
            $table->string('assembly', 10)->default('GRCh38');
            $table->json('raw_json')->nullable();
            $table->timestamp('clinvar_updated_at')->nullable();
            $table->timestamps();

            $table->index('gene_id');
            $table->index('classification');
            $table->index('variation_id');
            $table->index(['chromosome', 'position']);
            $table->index(['condition'], 'idx_variants_condition');
            $table->unique(['variation_id', 'submitter', 'rcv_accession'], 'idx_variants_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
