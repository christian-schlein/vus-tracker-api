<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, string $level = 'write'): Response
    {
        $token = $request->bearerToken() ?? $request->query('api_key');

        if (!$token) {
            return response()->json(['error' => 'API key required'], 401);
        }

        if ($level === 'write') {
            $valid = config('services.vus_import_key');
            if (!$valid || !hash_equals($valid, $token)) {
                return response()->json(['error' => 'Invalid write API key'], 403);
            }
        } else {
            // Read key: lighter weight, can be shared with frontend
            $valid = config('services.vus_read_key');
            if (!$valid || !hash_equals($valid, $token)) {
                return response()->json(['error' => 'Invalid API key'], 403);
            }
        }

        return $next($request);
    }
}
