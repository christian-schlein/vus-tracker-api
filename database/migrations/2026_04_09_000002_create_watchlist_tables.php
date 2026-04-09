<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('verify_token', 64);
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('gene_symbol', 50)->nullable();
            $table->unsignedInteger('variation_id')->nullable();
            $table->string('hgvs', 500)->nullable();
            $table->boolean('alert_on_reclassification')->default(true);
            $table->boolean('alert_on_new_submission')->default(true);
            $table->boolean('alert_on_pubmed')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->string('unsubscribe_token', 64);

            $table->index('email', 'idx_watch_email');
            $table->index('gene_symbol', 'idx_watch_gene');
            $table->index('variation_id', 'idx_watch_variant');
        });

        Schema::create('alert_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('watchlist_id');
            $table->string('alert_type', 50);
            $table->string('subject', 500)->nullable();
            $table->timestamp('sent_at')->useCurrent();

            $table->foreign('watchlist_id')->references('id')->on('watchlist')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_log');
        Schema::dropIfExists('watchlist');
    }
};