<?php

namespace App\Repositories;

use App\Models\OnsitePayment;

class OnsitePaymentRepository
{
    public function create(array $payload): OnsitePayment
    {
        return OnsitePayment::create($payload);
    }
}
