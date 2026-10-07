<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertDigest extends Mailable
{
    use Queueable, SerializesModels;

    public array $alerts;

    public function __construct(array $alerts)
    {
        $this->alerts = $alerts;
    }

    public function envelope(): Envelope
    {
        $critical = count(array_filter($this->alerts, fn($a) => $a->severity === 'critical'));
        $subject = $critical > 0
            ? "[CRITICAL] HVFRMS Alert Digest — {$critical} critical alert(s)"
            : "HVFRMS Alert Digest — " . count($this->alerts) . " new alert(s)";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.alert-digest');
    }
}
