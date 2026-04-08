<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reclassifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gene_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('variation_id_clinvar')->nullable();
            $table->string('hgvs', 500);
            $table->string('old_classification', 50);
            $table->string('new_classification', 50);
            $table->date('reclassified_at')->index();
            $table->string('submitter', 500)->nullable();
            $table->string('condition', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('gene_id');
            $table->index('variant_id');
            $table->index(['old_classification', 'new_classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reclassifications');
    }
};
