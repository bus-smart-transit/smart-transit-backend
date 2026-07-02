<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class PaymentController extends Controller
{
    use ApiResponse;
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    // Passenger checkout — one or more tickets in one transaction.
    public function checkoutOnline(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.trip_id' => 'required|integer|exists:trips,trip_id',
            'items.*.seat_type' => 'required|string|in:seated,standing',
            'items.*.origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'items.*.destination_stop_id' => 'required|integer|exists:stops,stop_id',
        ]);

        $passenger = $request->user()->passengerProfile; // adjust to your actual relation

        $result = $this->paymentService->checkoutOnline($passenger->passenger_id, $validated['items']);

        return $this->success($result, 'Checkout successful');
    }

    // Conductor onsite sale — same cart shape, plus optional passenger_id per item for walk-ups.
    public function checkoutOnsite(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.trip_id' => 'required|integer|exists:trips,trip_id',
            'items.*.seat_type' => 'required|string|in:seated,standing',
            'items.*.origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'items.*.destination_stop_id' => 'required|integer|exists:stops,stop_id',
            'items.*.passenger_id' => 'nullable|integer|exists:passenger_users,passenger_id',
        ]);

        $conductor = $request->user()->companyProfile; // adjust to your actual relation

        $result = $this->paymentService->checkoutOnsite($conductor->company_user_id, $validated['items']);

        return $this->success($result, 'Onsite sale recorded successfully');
    }
}
