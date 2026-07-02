<?php

namespace App\Repositories;

use App\Models\OnlinePayment;

class OnlinePaymentRepository
{
    public function create(array $payload): OnlinePayment
    {
        return OnlinePayment::create($payload);
    }
}
