<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Condition;
use App\Models\Gene;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => 'required|string|min:2|max:100'])['q'];
        $term = '%' . $q . '%';

        $genes = Gene::where('symbol', 'LIKE', $term)
            ->orWhere('full_name', 'LIKE', $term)
            ->limit(20)
            ->get(['symbol', 'full_name', 'total_variants', 'vus_count']);

        $conditions = Condition::where('name', 'LIKE', $term)
            ->limit(10)
            ->get(['id', 'name']);

        return response()->json([
            'data' => [
                'genes' => $genes,
                'conditions' => $conditions,
            ],
        ]);
    }
}
