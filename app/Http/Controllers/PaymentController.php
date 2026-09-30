<?php
namespace App\Http\Controllers;
use App\Services\PaymentService;
use App\Services\ConductorShiftService;
use App\Services\TripService;
use App\Http\Requests\OnlineCheckoutRequest;
use App\Http\Requests\OnsiteCheckoutRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;

class PaymentController extends Controller
{
    use ApiResponse;
    public function __construct(
        private PaymentService $paymentService,
        private TripService $tripService,
    )
    {
    }

    // C4: read-only payment state for the checkout return page (webhook-recorded, never client-set).
    public function onlineStatus(Request $request)
    {
        $data = $request->validate(['ref' => 'required|string|max:64']);
        $status = $this->paymentService->onlineStatus($data['ref']);

        return $status
            ? $this->success($status, 'Payment status retrieved')
            : $this->error('Payment not found.', 404);
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

            $payload = $request->validated();
            // Optional PayMongo billing.email: the signed-in account's email, or the
            // guest's own address. No other customer details are collected.
            $payload['billing_email'] = $passengerId ? $request->user()->email : $guestEmail;

            $result = $this->paymentService->checkoutOnline($passengerId, $guestEmail, $payload);
            return $this->success($result, 'Checkout initiated');
        } catch (ValidationException $e) {
            return $this->error('Checkout validation failed.', 422, $e->errors());
        } catch (ConnectionException $e) {
            return $this->error('Unable to connect to payment gateway. Please verify SSL certificate settings and try again.', 503);
        } catch (QueryException $e) {
            $sqlState = $e->getCode();
            if ($sqlState === '57014') {
                return $this->error('Checkout query timed out. Please retry in a few seconds.', 503);
            }
            return $this->error('Checkout failed while processing database records.', 500);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('checkoutOnline unexpected failure', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->error('Checkout failed due to an unexpected server error. Please try again.', 500);
        }
    }

    // Conductor: record an onsite cash sale (one or more tickets)
    public function checkoutOnsite(OnsiteCheckoutRequest $request)
    {
        $conductor = $request->user()?->companyProfile;
        if (!$conductor) return $this->error('Staff profile not found.', 404);

        $shiftStatus = app(ConductorShiftService::class)->getStatusForConductor($conductor->company_user_id);
        $openShift = $shiftStatus['open_shift'] ?? null;
        $hasOpenShift = (bool) ($shiftStatus['has_open_shift'] ?? false);
        if (!$hasOpenShift || !$openShift) {
            return $this->error('Start your shift first.', 422);
        }

        $activeTrip = $this->tripService->getCurrentTripForConductor($conductor->company_user_id);
        if (!$activeTrip) {
            return $this->error('No active trip found.', 404);
        }

        $payload = $request->validated();
        $requestedTripId = (int) ($payload['items'][0]['trip_id'] ?? 0);
        if ($requestedTripId > 0 && (int) ($activeTrip->trip_id ?? 0) !== $requestedTripId) {
            return $this->error('Your active shift is assigned to a different trip.', 422);
        }

        if ((int) ($openShift->trip_id ?? 0) !== (int) ($activeTrip->trip_id ?? 0)) {
            return $this->error('Your active shift is assigned to a different trip.', 422);
        }

        $result = $this->paymentService->checkoutOnsite(
            $conductor->company_user_id,
            $payload
        );
        return $this->success($result, 'Onsite sale recorded successfully');
    }

    public function passengerHistory(Request $request)
    {
        $passengerId = $request->user()?->passengerProfile?->passenger_id;

        if (!$passengerId) {
            return $this->error('Passenger profile not found.', 404);
        }

        $history = $this->paymentService->getPassengerHistoryFromPayments($passengerId);
        return $this->success($history, 'Payments history retrieved successfully');
    }
}
