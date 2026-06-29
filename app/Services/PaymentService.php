<?php

namespace App\Services;

use App\Repositories\PaymentRepository;
use App\Http\Resources\PaymentResource;

class PaymentService
{
    private PaymentRepository $paymentRepository;

    public function __construct(PaymentRepository $paymentRepository) 
    {
        $this->paymentRepository = $paymentRepository;
    }

    public function listPayment(int $perPage = 15)
    {
        $collection = $this->paymentRepository->paginate($perPage);
        return PaymentResource::collection($collection);
    }

    public function createPayment(array $payload)
    {
        $model = $this->paymentRepository->create($payload);
        
    }

    public function getPayment(string $uuid)
    {
        $model = $this->paymentRepository->findByUuid($uuid);
        
    }

    public function getPaymentByField(string $field, $value)
    {
        $model = $this->paymentRepository->findByField($field, $value);
        
    }

    public function updatePayment(string $uuid, array $payload)
    {
        $model = $this->paymentRepository->update($uuid, $payload);
        
    }

    public function deletePayment(string $uuid)
    {
        $this->paymentRepository->delete($uuid);
        return true;
    }

    public function restorePayment(string $uuid)
    {
        $model = $this->paymentRepository->restore($uuid);
        
    }
}