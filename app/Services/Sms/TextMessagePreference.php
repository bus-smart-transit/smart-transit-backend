<?php

namespace App\Services\Sms;

use App\Models\User;
use App\Repositories\UserRepository;

/**
 * Batch 67 (user, 2026-11-13): ONE owner for "may SmartTransit text this person?". Every account starts with texts off;
 * the person turns them on themselves on their Account page. SmsNotifier asks allows() before every text it sends to an
 * account (passengers, Drivers, Chauffeurs, Operators). A number typed at checkout is never texted without an account
 * that said yes.
 */
class TextMessagePreference
{
    public function __construct(private UserRepository $users) {}

    /** True only when this account (a User, or anything carrying sms_opt_in) turned text messages on. */
    public static function allows(?object $user): bool
    {
        return (bool) ($user?->sms_opt_in ?? false);
    }

    public function set(User $user, bool $enabled): bool
    {
        $this->users->setSmsOptIn($user, $enabled);

        return $enabled;
    }
}
