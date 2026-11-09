<?php

use App\Mail\TicketTrackingMail;
use App\Support\TravellerContact;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Batch 51 item 7 (user, 2026-11-09): a passenger may book for someone else, with that person's name and an email or a
// Philippine mobile number. The ticket still goes to the buyer's email until an SMS service is chosen.

it('accepts an email or a Philippine mobile number and stores one form', function () {
    expect(TravellerContact::normalize('Juan@Example.com'))->toBe('juan@example.com')
        ->and(TravellerContact::normalize('0917 123 4567'))->toBe('+639171234567')
        ->and(TravellerContact::normalize('+63-917-123-4567'))->toBe('+639171234567')
        ->and(TravellerContact::normalize('639171234567'))->toBe('+639171234567')
        ->and(TravellerContact::normalize('12345'))->toBeNull()
        ->and(TravellerContact::normalize('not an email'))->toBeNull()
        ->and(TravellerContact::isPhone('+639171234567'))->toBeTrue();
});

it('names the traveller in the ticket email, sent to the buyer', function () {
    $mail = new TicketTrackingMail('Buyer', [], '60', 'buyer@example.com', false, [], 'Lola Ana', '+639171234567');
    $html = $mail->render();

    expect($html)->toContain('Booked for <strong>Lola Ana</strong> (+639171234567)')
        ->and((new TicketTrackingMail('Buyer', [], '60'))->render())->not->toContain('Booked for');
});
