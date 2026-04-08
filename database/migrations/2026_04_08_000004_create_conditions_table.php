<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conditions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 500);
            $table->string('medgen_id', 20)->nullable()->index();
            $table->string('omim_id', 20)->nullable();
            $table->string('orphanet_id', 20)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['name'], 'idx_conditions_name');
        });

        Schema::create('condition_gene', function (Blueprint $table) {
            $table->foreignId('condition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gene_id')->constrained()->cascadeOnDelete();
            $table->primary(['condition_id', 'gene_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('condition_gene');
        Schema::dropIfExists('conditions');
    }
};
