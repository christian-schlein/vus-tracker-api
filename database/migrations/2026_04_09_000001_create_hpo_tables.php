<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hpo_terms', function (Blueprint $table) {
            $table->id();
            $table->string('hpo_id', 20)->unique();
            $table->string('name', 500);
            $table->index([DB::raw('name(100)')], 'idx_hpo_name');
            $table->fullText('name', 'ft_hpo_name');
        });

        Schema::create('hpo_gene', function (Blueprint $table) {
            $table->foreignId('hpo_term_id')->constrained('hpo_terms')->cascadeOnDelete();
            $table->string('gene_symbol', 50);
            $table->unsignedBigInteger('gene_id')->nullable();
            $table->string('disease_id', 50)->nullable();
            $table->index('gene_symbol', 'idx_hpo_gene_symbol');
            $table->index('hpo_term_id', 'idx_hpo_gene_term');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hpo_gene');
        Schema::dropIfExists('hpo_terms');
    }
};
