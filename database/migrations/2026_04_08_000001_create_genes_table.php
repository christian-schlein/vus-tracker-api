<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genes', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 50)->unique();
            $table->string('full_name', 255)->nullable();
            $table->string('omim_id', 20)->nullable()->index();
            $table->string('medgen_id', 20)->nullable();
            $table->string('orphanet_id', 20)->nullable();
            $table->string('hgnc_id', 20)->nullable();
            $table->unsignedInteger('total_variants')->default(0);
            $table->unsignedInteger('pathogenic_count')->default(0);
            $table->unsignedInteger('likely_pathogenic_count')->default(0);
            $table->unsignedInteger('vus_count')->default(0)->index();
            $table->unsignedInteger('likely_benign_count')->default(0);
            $table->unsignedInteger('benign_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('genes');
    }
};
