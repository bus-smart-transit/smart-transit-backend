<?php
namespace App\Http\Controllers;
use App\Services\PaymentService;
use App\Http\Requests\OnlineCheckoutRequest;
use App\Http\Requests\OnsiteCheckoutRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    use ApiResponse;
    public function __construct(private PaymentService $paymentService)
    {
    }

    // Passenger: buy one or more tickets in one transaction
    public function checkoutOnline(OnlineCheckoutRequest $request)
    {
        try {
            $passenger = $request->user()?->passengerProfile;
            $passengerId = $passenger?->passenger_id;

            if ($request->user() && !$passengerId) {
                return $this->error("Account is invalid. Please log out and try again.", 422);
            }

            $guestEmail = $passengerId ? null : $request->validated('guest_email');

            $result = $this->paymentService->checkoutOnline($passengerId, $guestEmail, $request->validated());
            return $this->success($result, 'Checkout initiated');
        } catch (ValidationException $e) {
            return $this->error('Checkout validation failed.', 422, $e->errors());
        } catch (ConnectionException $e) {
            return $this->error('Unable to connect to payment gateway. Please verify SSL certificate settings and try again.', 503);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
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
