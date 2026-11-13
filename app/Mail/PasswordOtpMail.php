<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One-time passcode (OTP) sent to an eligible, active non-admin account so the
 * holder can reset their own password. Carries only the display name and the
 * numeric code — never the account password or any other credential.
 */
class PasswordOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $fullName,
        public string $code,
        public int $expiresMinutes = 10,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your HVFRMS password reset code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-otp');
    }
}
