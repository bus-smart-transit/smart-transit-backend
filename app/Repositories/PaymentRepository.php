<?php

namespace App\Repositories;

use App\Models\Payment;
use Illuminate\Support\Str;

class PaymentRepository
{
    public function create(array $payload): Payment
    {
        $payload['payment_uuid'] = $payload['payment_uuid'] ?? (string) Str::uuid();
        $payload['payment_created'] = $payload['payment_created'] ?? now();
        return Payment::create($payload);
    }

    public function findById(int $paymentId): ?Payment
    {
        return Payment::with('tickets')->find($paymentId);
    }

    public function markStatus(int $paymentId, string $status, bool $isValid = true): bool
    {
        return Payment::where('payment_id', $paymentId)->update([
            'status' => $status,
            'is_valid' => $isValid,
        ]) > 0;
    }
}
