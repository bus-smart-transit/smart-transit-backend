<?php

namespace App\Support;

/**
 * Batch 74 (user, 2026-11-14): the one rule for when the Chauffeur may scan tickets, sell cash tickets and check
 * passengers in: only once the Driver has pressed Start boarding, and while the bus is on the road. A trip still
 * scheduled or delayed has not opened its doors yet.
 */
final class BoardingOpen
{
    public const STATUSES = ['boarding', 'departed', 'in-progress'];

    public const MESSAGE = 'Boarding has not started yet. Ask the Driver to press Start boarding.';

    public static function allows(?string $status): bool
    {
        return in_array((string) $status, self::STATUSES, true);
    }
}
