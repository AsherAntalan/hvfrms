<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password-reset link sent to an eligible, active account.
 *
 * Carries only the ready-made reset URL (built by the caller from a named
 * route) and the recipient's display name — never the raw token on its own,
 * the account password, or any credential.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $fullName,
        public string $resetUrl,
        public int $expiresMinutes = 60,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your HVFRMS password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset');
    }
}
