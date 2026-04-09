<?php

namespace App\Mail;

use App\Models\Watchlist;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifySubscription extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Watchlist $watch) {}

    public function envelope(): Envelope
    {
        $target = $this->watch->gene_symbol ?? $this->watch->hgvs ?? "Variant #{$this->watch->variation_id}";
        return new Envelope(
            subject: "Verify your VUS Tracker alert for {$target}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $target = e($this->watch->gene_symbol ?? $this->watch->hgvs ?? "Variant #{$this->watch->variation_id}");
        $verifyUrl = url("/api/v1/watchlist/verify/{$this->watch->verify_token}");
        $unsubUrl = url("/api/v1/watchlist/unsubscribe/{$this->watch->unsubscribe_token}");

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="utf-8"></head>
        <body style="font-family: -apple-system, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #1a1a1a;">
            <h2 style="color: #2563eb;">VUS Tracker Alert</h2>
            <p>You requested email alerts for <strong>{$target}</strong>.</p>
            <p>Click the button below to verify your subscription:</p>
            <p style="margin: 30px 0;">
                <a href="{$verifyUrl}" style="background: #2563eb; color: #fff; padding: 12px 28px; text-decoration: none; border-radius: 6px; font-weight: 600;">
                    Verify Subscription
                </a>
            </p>
            <p style="color: #6b7280; font-size: 13px;">If you did not request this, ignore this email.</p>
            <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;">
            <p style="color: #9ca3af; font-size: 12px;">
                <a href="{$unsubUrl}" style="color: #9ca3af;">Unsubscribe</a>
                &middot; VUS Tracker by Schlein Lab
            </p>
        </body>
        </html>
        HTML;
    }
}