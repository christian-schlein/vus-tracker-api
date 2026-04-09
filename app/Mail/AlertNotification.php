<?php

namespace App\Mail;

use App\Models\Watchlist;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Watchlist $watch,
        public string $alertType,
        public string $alertSubject,
        public string $alertBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->alertSubject,
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
        $body = $this->alertBody;
        $unsubUrl = url("/api/v1/watchlist/unsubscribe/{$this->watch->unsubscribe_token}");
        $target = e($this->watch->gene_symbol ?? $this->watch->hgvs ?? "Variant #{$this->watch->variation_id}");

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="utf-8"></head>
        <body style="font-family: -apple-system, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #1a1a1a;">
            <h2 style="color: #2563eb;">VUS Tracker Alert</h2>
            <p style="color: #6b7280; font-size: 13px;">Watching: <strong>{$target}</strong></p>
            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin: 20px 0;">
                {$body}
            </div>
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