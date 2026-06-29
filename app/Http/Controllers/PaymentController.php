<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function index(Request $request)
    {
        return $this->paymentService->listPayment($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->paymentService->createPayment($request->all());
    }

    public function show(string $uuid)
    {
        return $this->paymentService->getPayment($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->paymentService->updatePayment($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->paymentService->deletePayment($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->paymentService->restorePayment($uuid);
    }
}