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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your SmartTransit Login Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.passenger-otp',
            with: [
                'originalEmail' => $this->recipientEmail,
            ],
        );
    }
}
