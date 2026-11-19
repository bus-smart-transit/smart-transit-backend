<?php

namespace App\Support;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * 2026-11-19 (user: "Background jobs 10 failed"): every failed job was an email to a TEST address on a reserved domain
 * (b75-test@example.com and the like, RFC 2606/6761). Resend refuses those, so each one failed three times and stayed
 * in failed_jobs. An email whose recipients are ALL on reserved domains is now skipped before it is sent (logged, not
 * failed). Real addresses are never touched, and one real recipient among test ones still gets the email.
 */
class ReservedEmailDomain
{
    /** The second-level names and the top-level names reserved for tests and examples. */
    private const RESERVED_NAMES = ['example.com', 'example.net', 'example.org'];

    private const RESERVED_TLDS = ['example', 'test', 'invalid', 'localhost'];

    public static function isReserved(string $address): bool
    {
        $domain = strtolower(trim((string) substr(strrchr($address, '@') ?: '', 1)));
        if ($domain === '') {
            return false;
        }
        foreach (self::RESERVED_NAMES as $name) {
            if ($domain === $name || str_ends_with($domain, '.'.$name)) {
                return true;
            }
        }

        return in_array(substr(strrchr('.'.$domain, '.') ?: '', 1), self::RESERVED_TLDS, true);
    }

    /** True when the email has recipients and every one of them is on a reserved domain. */
    public static function onlyReservedRecipients(Email $message): bool
    {
        $addresses = array_map(fn (Address $a) => $a->getAddress(), [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()]);

        return $addresses !== [] && array_filter($addresses, fn (string $a) => ! self::isReserved($a)) === [];
    }
}
