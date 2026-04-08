<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reclassification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReclassificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Reclassification::with('gene:id,symbol')
            ->orderByDesc('reclassified_at');

        if ($request->filled('date_from')) {
            $query->where('reclassified_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('reclassified_at', '<=', $request->input('date_to'));
        }
        if ($request->filled('classification')) {
            $query->where('new_classification', $request->input('classification'));
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }
}
