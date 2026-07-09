<?php
namespace App\Http\Controllers;
use App\Services\PaymentService;
use App\Http\Requests\OnlineCheckoutRequest;
use App\Http\Requests\OnsiteCheckoutRequest;
use App\Traits\ApiResponse;

class PaymentController extends Controller
{
    use ApiResponse;
    public function __construct(private PaymentService $paymentService)
    {
    }

    // Passenger: buy one or more tickets in one transaction
    public function checkoutOnline(OnlineCheckoutRequest $request)
    {
        $passenger = $request->user()?->passengerProfile;
        $passengerId = $passenger?->passenger_id;

        if ($request->user() && !$passengerId) {
            //TEST
            return $this->error("Account is invalid. Please log out and try again.");
        }

        $guestEmail = $passengerId ? null : $request->validated('guest_email');

        $result = $this->paymentService->checkoutOnline($passengerId, $guestEmail, $request->validated());
        return $this->success($result, 'Checkout initiated');
    }

    // Conductor: record an onsite cash sale (one or more tickets)
    public function checkoutOnsite(OnsiteCheckoutRequest $request)
    {
        $conductor = $request->user()->companyProfile;
        $result = $this->paymentService->checkoutOnsite(
            $conductor->company_user_id,
            $request->validated()
        );
        return $this->success($result, 'Onsite sale recorded successfully');
    }
}
