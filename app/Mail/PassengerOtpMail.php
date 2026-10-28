<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PassengerOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $name,
        public readonly string $recipientEmail = '',
        public readonly bool $verify = false, // 2026-10-28: true for "verify your email" (sign-up)
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->verify ? 'Verify your SmartTransit email' : 'Your SmartTransit Login Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.passenger-otp',
            with: [
                'originalEmail' => $this->recipientEmail,
                'verify' => $this->verify,
            ],
        );
    }
}
