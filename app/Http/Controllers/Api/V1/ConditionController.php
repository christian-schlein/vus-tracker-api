<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Condition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConditionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Condition::query();

        if ($request->filled('q')) {
            $query->where('name', 'LIKE', '%' . $request->input('q') . '%');
        }

        $conditions = $query->withCount('genes')
            ->orderByDesc('genes_count')
            ->paginate($request->integer('per_page', 50));

        return response()->json($conditions);
    }

    public function genes(Condition $condition): JsonResponse
    {
        $genes = $condition->genes()
            ->select('genes.id', 'genes.symbol', 'genes.full_name', 'genes.vus_count', 'genes.total_variants')
            ->orderByDesc('vus_count')
            ->get();

        return response()->json(['data' => $genes]);
    }
}
