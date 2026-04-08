<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedInteger('total_variants')->default(0);
            $table->unsignedInteger('total_genes')->default(0);
            $table->unsignedInteger('total_vus')->default(0);
            $table->unsignedInteger('total_reclassifications')->default(0);
            $table->unsignedInteger('new_variants_today')->default(0);
            $table->unsignedInteger('new_reclass_today')->default(0);
            $table->json('snapshot_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
