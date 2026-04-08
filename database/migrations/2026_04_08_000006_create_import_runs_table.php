<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50)->default('github_actions');
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->unsignedInteger('variants_processed')->default(0);
            $table->unsignedInteger('reclass_processed')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->text('error_log')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
