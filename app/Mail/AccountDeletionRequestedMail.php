<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 2026-11-05: sent once when a passenger asks to delete their account (AccountDeletionService::request).
 */
class AccountDeletionRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $deletesOn,
        public readonly int $graceDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your account will be deleted');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-deletion-requested');
    }
}
