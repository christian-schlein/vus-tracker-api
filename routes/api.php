<?php

use App\Http\Controllers\Api\V1\GeneController;
use App\Http\Controllers\Api\V1\ReclassificationController;
use App\Http\Controllers\Api\V1\ConditionController;
use App\Http\Controllers\Api\V1\StatsController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\ImportController;
use App\Http\Controllers\Api\V1\PhenotypeController;
use App\Http\Controllers\Api\V1\WatchlistController;
use App\Http\Middleware\ApiKeyAuth;
use Illuminate\Support\Facades\Route;

Route::prefix("v1")->middleware([ApiKeyAuth::class . ":read", "throttle:api-read"])->group(function () {
    Route::get("stats", [StatsController::class, "index"]);
    Route::get("submissions-timeline", [StatsController::class, "submissionsTimeline"]);
    Route::get("search", [SearchController::class, "index"]);

    Route::get("genes", [GeneController::class, "index"]);
    Route::get("genes/{symbol}", [GeneController::class, "show"]);
    Route::get("genes/{symbol}/variants", [GeneController::class, "variants"]);
    Route::get("genes/{symbol}/reclassifications", [GeneController::class, "reclassifications"]);
    Route::get("genes/{symbol}/timeline", [GeneController::class, "timeline"]);
    Route::get("genes/{symbol}/concordance", [GeneController::class, "concordance"]);
    Route::get("genes/{symbol}/survival", [GeneController::class, "survival"]);
    Route::get("genes/{symbol}/drift", [GeneController::class, "drift"]);
    Route::get("genes/{symbol}/genome-browser", [GeneController::class, "genomeBrowser"]);

    Route::get("reclassifications", [ReclassificationController::class, "index"]);
    Route::get("conditions", [ConditionController::class, "index"]);
    Route::get("conditions/{condition}/genes", [ConditionController::class, "genes"]);

    Route::get("phenotype/search", [PhenotypeController::class, "search"]);
    Route::get("phenotype/genes", [PhenotypeController::class, "genes"]);
    Route::get("phenotype/term/{hpoId}", [PhenotypeController::class, "term"]);

    // Watchlist / Alert system
    Route::post("watchlist/subscribe", [WatchlistController::class, "subscribe"])
        ->middleware("throttle:watchlist-subscribe");
    Route::get("watchlist/verify/{token}", [WatchlistController::class, "verify"])
        ->where('token', '[A-Za-z0-9]{64}');
    Route::get("watchlist/unsubscribe/{token}", [WatchlistController::class, "unsubscribe"])
        ->where('token', '[A-Za-z0-9]{64}');
    Route::get("watchlist/status", [WatchlistController::class, "status"]);
});

Route::prefix("v1/import")->middleware([ApiKeyAuth::class . ":write", "throttle:api-write"])->group(function () {
    Route::post("variants", [ImportController::class, "variants"]);
    Route::post("reclassifications", [ImportController::class, "reclassifications"]);
    Route::post("complete", [ImportController::class, "complete"]);
});