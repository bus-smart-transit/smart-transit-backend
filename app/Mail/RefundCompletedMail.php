<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 2026-11-04 (Batch 45, Todo R): a refund has been completed, sent once to the buyer (RefundCompletedMailer).
 * `accountEmail` is shown only when the mail was redirected (MAIL_INTERCEPT_EMAIL, the demo setup).
 */
class RefundCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly float $amount,
        public readonly string $wallet,
        public readonly string $reference,
        public readonly string $route,
        public readonly string $date,
        public readonly string $time,
        public readonly string $accountEmail = '',
        public readonly bool $redirected = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your refund is done');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.refund-completed');
    }
}
