<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Operational alert emailed to administrators for key order events
 * (new order request, order received, refund requested). Carries only
 * descriptive text — never credentials.
 */
class AdminNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public string $heading,
        public array $lines,
        public string $subjectLine = 'HVFRMS notification',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.admin-notification');
    }
}
