<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Follow your bus": one email after an online payment, with one tracking link per trip bought (§4.10: one for a one-way
 * purchase however many tickets, two for a roundtrip). The link is the whole credential for following that bus (no
 * account), so the email says not to share it. `accountEmail` is shown only when the mail was redirected to another
 * address (the demo setup).
 *
 * @phpstan-type Item array{route: string, date: string, time: string, from: string, to: string, tickets: string, link: string}
 */
class TicketTrackingMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, string>>  $items
     */
    public function __construct(
        public readonly string $name,
        public readonly array $items,
        public readonly string $opensMinutesBefore,
        public readonly string $accountEmail = '',
        public readonly bool $redirected = false,
        /** @var list<array{label: string, png: string}> §4.10: the boarding QR (base64 PNG), one group QR or one per ticket */
        public readonly array $qrCodes = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: count($this->items) > 1 ? 'Follow your buses on SmartTransit' : 'Follow your bus on SmartTransit');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-tracking');
    }
}
