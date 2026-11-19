<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The trip was cancelled by the operator and the online tickets of one purchase are being refunded automatically
 * (RefundCancelledTripTickets). `needsReview`: the amount could not be worked out safely, so the operator checks it
 * first. `accountEmail` is shown only when the mail was redirected (the demo setup).
 */
class TripCancelledRefundMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $route,
        public readonly string $date,
        public readonly string $time,
        public readonly int $tickets,
        public readonly float $amount,
        public readonly string $wallet,
        public readonly bool $needsReview,
        public readonly string $accountEmail = '',
        public readonly bool $redirected = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your trip was cancelled: refund on the way');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.trip-cancelled-refund');
    }
}
