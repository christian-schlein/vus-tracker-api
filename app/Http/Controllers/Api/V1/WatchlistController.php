<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\VerifySubscription;
use App\Models\Watchlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class WatchlistController extends Controller
{
    /**
     * POST /api/v1/watchlist/subscribe
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'gene_symbol' => 'nullable|string|max:50|regex:/^[A-Z0-9\-]+$/i',
            'variation_id' => 'nullable|integer|min:1',
            'hgvs' => 'nullable|string|max:500',
            'alert_on_reclassification' => 'boolean',
            'alert_on_new_submission' => 'boolean',
            'alert_on_pubmed' => 'boolean',
        ]);

        // Must watch at least one thing
        if (empty($validated['gene_symbol']) && empty($validated['variation_id']) && empty($validated['hgvs'])) {
            return response()->json([
                'error' => 'At least one of gene_symbol, variation_id, or hgvs is required.',
            ], 422);
        }

        // Rate limit: max 5 subscriptions per email
        $existingCount = Watchlist::where('email', $validated['email'])->count();
        if ($existingCount >= 5) {
            return response()->json([
                'error' => 'Maximum of 5 subscriptions per email address.',
            ], 429);
        }

        // Check for duplicate subscription
        $duplicate = Watchlist::where('email', $validated['email'])
            ->where(function ($q) use ($validated) {
                if (!empty($validated['variation_id'])) {
                    $q->where('variation_id', $validated['variation_id']);
                } elseif (!empty($validated['hgvs'])) {
                    $q->where('hgvs', $validated['hgvs']);
                } elseif (!empty($validated['gene_symbol'])) {
                    $q->where('gene_symbol', $validated['gene_symbol'])
                      ->whereNull('variation_id')
                      ->whereNull('hgvs');
                }
            })
            ->exists();

        if ($duplicate) {
            return response()->json([
                'error' => 'You already have a subscription for this variant/gene.',
            ], 409);
        }

        $watch = Watchlist::create([
            'email' => $validated['email'],
            'verify_token' => Str::random(64),
            'unsubscribe_token' => Str::random(64),
            'gene_symbol' => $validated['gene_symbol'] ?? null,
            'variation_id' => $validated['variation_id'] ?? null,
            'hgvs' => $validated['hgvs'] ?? null,
            'alert_on_reclassification' => $validated['alert_on_reclassification'] ?? true,
            'alert_on_new_submission' => $validated['alert_on_new_submission'] ?? true,
            'alert_on_pubmed' => $validated['alert_on_pubmed'] ?? false,
        ]);

        Mail::to($watch->email)->send(new VerifySubscription($watch));

        return response()->json([
            'message' => 'Verification email sent. Please check your inbox.',
        ], 201);
    }

    /**
     * GET /api/v1/watchlist/verify/{token}
     */
    public function verify(string $token)
    {
        $watch = Watchlist::where('verify_token', $token)->first();

        if (!$watch) {
            return response(
                $this->htmlPage('Invalid Link', '<p>This verification link is invalid or has expired.</p>'),
                404
            )->header('Content-Type', 'text/html');
        }

        if ($watch->isVerified()) {
            return response(
                $this->htmlPage('Already Verified', '<p>Your subscription is already verified.</p>'),
                200
            )->header('Content-Type', 'text/html');
        }

        $watch->update(['email_verified_at' => now()]);

        $target = e($watch->gene_symbol ?? $watch->hgvs ?? "Variant #{$watch->variation_id}");

        return response(
            $this->htmlPage('Subscription Verified', "<p>Your alert subscription for <strong>{$target}</strong> is now active.</p><p>You will receive email notifications when changes are detected.</p>"),
            200
        )->header('Content-Type', 'text/html');
    }

    /**
     * GET /api/v1/watchlist/unsubscribe/{token}
     */
    public function unsubscribe(string $token)
    {
        $watch = Watchlist::where('unsubscribe_token', $token)->first();

        if (!$watch) {
            return response(
                $this->htmlPage('Invalid Link', '<p>This unsubscribe link is invalid or the subscription was already removed.</p>'),
                404
            )->header('Content-Type', 'text/html');
        }

        $target = e($watch->gene_symbol ?? $watch->hgvs ?? "Variant #{$watch->variation_id}");
        $watch->delete();

        return response(
            $this->htmlPage('Unsubscribed', "<p>Your alert subscription for <strong>{$target}</strong> has been removed.</p><p>You will no longer receive notifications.</p>"),
            200
        )->header('Content-Type', 'text/html');
    }

    /**
     * GET /api/v1/watchlist/status?email=...
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $watches = Watchlist::where('email', $request->query('email'))
            ->whereNotNull('email_verified_at')
            ->get(['id', 'gene_symbol', 'variation_id', 'hgvs', 'alert_on_reclassification', 'alert_on_new_submission', 'alert_on_pubmed', 'created_at']);

        return response()->json([
            'count' => $watches->count(),
            'subscriptions' => $watches,
        ]);
    }

    /**
     * Build a minimal HTML page.
     */
    private function htmlPage(string $title, string $body): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title} — VUS Tracker</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 500px; margin: 80px auto; padding: 20px; color: #1a1a1a; text-align: center; }
                h1 { color: #2563eb; font-size: 24px; }
                p { color: #374151; line-height: 1.6; }
                a { color: #2563eb; }
            </style>
        </head>
        <body>
            <h1>{$title}</h1>
            {$body}
            <p style="margin-top: 40px; font-size: 13px; color: #9ca3af;">VUS Tracker by Schlein Lab</p>
        </body>
        </html>
        HTML;
    }
}