<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The password reset email: one link to the frontend reset page (passenger or staff, by the account's role) with the
 * token and email already filled in. `accountEmail` is the account the link is for; it is shown only when the mail
 * was redirected to another address (the demo setup), so the reader knows which account it belongs to.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $resetUrl,
        public readonly int $expiresInMinutes,
        public readonly string $accountEmail = '',
        public readonly bool $redirected = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your SmartTransit password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.password-reset');
    }
}
