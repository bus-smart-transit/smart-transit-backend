<?php

use App\Support\ReservedEmailDomain;
use Illuminate\Support\Facades\Mail;

// 2026-11-19 (user: "Background jobs 10 failed"): emails only to TEST addresses on reserved domains are skipped instead
// of failing at the email provider; a real address is always sent to. SAMPLE addresses only.

it('knows the reserved test domains and leaves real ones alone', function () {
    foreach (['a@example.com', 'b@mail.example.org', 'c@site.test', 'd@x.invalid', 'e@demo.example', 'f@localhost'] as $address) {
        expect(ReservedEmailDomain::isReserved($address))->toBeTrue();
    }
    foreach (['a@gmail.com', 'b@examples.com', 'c@testing.ph', 'd@smarttransitdavao.tech'] as $address) {
        expect(ReservedEmailDomain::isReserved($address))->toBeFalse();
    }
});

it('skips an email whose recipients are all test addresses, and sends one with a real recipient', function () {
    config(['mail.default' => 'array']);
    Mail::raw('Sample', fn ($m) => $m->to('rider@example.com')->subject('Sample test only'));
    Mail::raw('Sample', fn ($m) => $m->to(['rider@example.com', 'rider@sample-real-domain.ph'])->subject('Sample with a real one'));

    $sent = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getSubject())->all();
    expect($sent)->toBe(['Sample with a real one']);
});
