<?php

namespace App\Services;

use App\Exceptions\CheckoutIdempotencyConflict;
use App\Exceptions\PayMongoCheckoutOutcomeUnknown;
use App\Exceptions\PayMongoNotConfigured;
use App\Models\Payment;
use App\Repositories\OnlinePaymentRepository;
use App\Repositories\OnsitePaymentRepository;
use App\Repositories\PassengerRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\TripRepository;
use App\Support\Database\RlsContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private OnlinePaymentRepository $onlinePaymentRepository,
        private OnsitePaymentRepository $onsitePaymentRepository,
        private TicketService $ticketService,
        private PayMongoService $payMongoService,
        private RewardService $rewardService,
        private TripRepository $tripRepository,
        private PassengerRepository $passengerRepository,
        private FcmService $fcmService,
        private TripCancellationService $tripCancellations,
        private ShiftCashService $shiftCash,
        private TrackingLinkMailer $trackingMail,
    ) {}

    /**
     * $payload = ['items' => [...], 'payment_channel']
     * Each item is EITHER:
     *   stop-based:   ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     *   custom-point: ['trip_id','seat_type','origin_lat','origin_lng','destination_lat','destination_lng']
     *
     * No ticket rows are created here. Each item's seat/standing capacity
     * is reserved and its fare computed via TicketService::reserveAndPrice(),
     * and that reservation is stored as JSON on payments.items_payload.
     * Actual `tickets` rows are only created once the webhook confirms
     * payment — see PaymentService::finalizeOnlinePayment().
     */
    /**
     * C4: what the return page shows. The webhook is the source of truth for
     * payment state; this only reports what it (or the hold expiry job) has
     * already recorded, so the return URL can refresh the UI without ever
     * deciding whether a payment succeeded.
     *
     * @return array{transaction_reference: string, status: string, hold_expires_at: string|null}|null
     */
    public function onlineStatus(string $transactionReference): ?array
    {
        $payment = $this->paymentRepository->findByTransactionReference($transactionReference);
        if (! $payment || $payment->payment_method !== 'online') {
            return null;
        }

        $payment = $this->confirmFromProviderIfMissed($payment);
        $status = (string) $payment->status;
        $outcomeUnknown = $payment->idempotency_state === 'unknown';
        if (! $outcomeUnknown && $status === 'pending' && $payment->hold_expires_at && Carbon::parse($payment->hold_expires_at)->isPast()) {
            $status = 'expired';
        }

        $result = [
            'transaction_reference' => $payment->transaction_reference,
            'status' => $status,
            'hold_expires_at' => $payment->hold_expires_at ? Carbon::parse($payment->hold_expires_at)->toIso8601String() : null,
        ];
        if ($outcomeUnknown) {
            $result['outcome_unknown'] = true;
        }

        return $result;
    }

    /**
     * The webhook normally confirms a payment, but it can miss us (tunnel down, URL not public, delivery failure).
     * While the return page is waiting, ask PayMongo about the checkout session and, if it was paid, finish the
     * payment through the same idempotent path the webhook uses. Asked at most once every few seconds per payment.
     */
    private function confirmFromProviderIfMissed(Payment $payment): Payment
    {
        if ($payment->status !== 'pending' || ! $payment->gateway_reference) {
            return $payment;
        }
        if (! Cache::add('payment_status_provider_check:'.$payment->payment_id, 1, 4)) {
            return $payment;
        }

        try {
            $session = $this->payMongoService->lookupCheckoutSession((string) $payment->gateway_reference);
        } catch (PayMongoNotConfigured) {
            return $payment;
        }
        if (! ($session['paid'] ?? false)) {
            return $payment;
        }

        $updates = array_filter([
            'gateway_payment_id' => empty($payment->gateway_payment_id) ? $session['gateway_payment_id'] : null,
            'payment_intent_id' => empty($payment->payment_intent_id) ? $session['payment_intent_id'] : null,
        ]);
        if ($updates !== []) {
            $payment->forceFill($updates)->save();
        }

        $this->finalizeOnlinePayment($payment);
        Log::info('Payment confirmed by asking PayMongo (webhook had not arrived).', ['payment_id' => $payment->payment_id]);

        return $payment->fresh() ?? $payment;
    }

    public function checkoutOnline(?int $passengerId, ?string $guestEmail, array $payload, ?string $idempotencyKey = null): array
    {
        return RlsContext::withBypass(function () use ($passengerId, $guestEmail, $payload, $idempotencyKey) {
            $identity = $this->idempotencyIdentity(
                $idempotencyKey,
                $passengerId !== null ? 'passenger:'.$passengerId : 'guest:'.strtolower((string) ($guestEmail ?? 'anonymous')),
                $payload,
            );
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            $startedAt = microtime(true);

            try {
                $prepared = DB::transaction(function () use ($passengerId, $guestEmail, $payload, $startedAt, $identity) {
                    // PostgreSQL only: bounded waits for row locks. (Other drivers, i.e. the SQLite test database, have no such setting.)
                    if (DB::getDriverName() === 'pgsql') {
                        DB::statement("SET LOCAL lock_timeout TO '5s'");
                        DB::statement("SET LOCAL statement_timeout TO '12s'");
                    }

                    if ($identity !== null) {
                        $existing = $this->paymentRepository->findByIdempotencyKey($identity['scope_hash'], $identity['key_hash'], true);
                        if ($existing) {
                            return ['idempotency_replay' => $existing];
                        }
                    }

                    $pointsRequested = max(0, (int) ($payload['reward_points_to_use'] ?? 0));

                    if ($pointsRequested > 0 && ! $passengerId) {
                        throw ValidationException::withMessages([
                            'reward_points_to_use' => ['Reward points can only be used by signed-in passengers.'],
                        ]);
                    }

                    $payment = $this->paymentRepository->create([
                        'amount' => 0,
                        'transaction_reference' => 'TXN-'.strtoupper(bin2hex(random_bytes(8))),
                        'payment_method' => 'online',
                        'payment_channel' => $payload['payment_channel'],
                        'guest_email' => $guestEmail,
                        'status' => 'pending',
                        'is_valid' => true,
                        'idempotency_scope_hash' => $identity['scope_hash'] ?? null,
                        'idempotency_key_hash' => $identity['key_hash'] ?? null,
                        'idempotency_request_hash' => $identity['request_hash'] ?? null,
                        'idempotency_state' => $identity !== null ? 'processing' : null,
                        // §4.10: one group QR in the email (default) or one QR per ticket.
                        'email_group_qr' => (bool) ($payload['email_group_qr'] ?? true),
                    ]);

                    // Keep one online_payment row per online transaction.
                    // Passenger ownership is persisted in items_payload, not
                    // in online_payment, so the table can stay minimal.
                    $this->onlinePaymentRepository->create([
                        'payment_id' => $payment->payment_id,
                    ]);

                    $total = 0;
                    $reservedItems = [];
                    $lineItems = [];
                    $groupedItems = [];

                    foreach ($payload['items'] as $item) {
                        // An item takes the booking's date. Roundtrip booking is retired, so no item carries a journey leg
                        // (OnlineCheckoutRequest refuses one); old roundtrip tickets keep the leg they were sold with.
                        $item['booking_date'] = $item['booking_date'] ?? $payload['booking_date'] ?? null;
                        unset($item['journey_leg']);

                        $itemKey = json_encode([
                            'trip_id' => (int) ($item['trip_id'] ?? 0),
                            'seat_type' => (string) ($item['seat_type'] ?? ''),
                            'booking_date' => isset($item['booking_date']) ? (string) $item['booking_date'] : null,
                            'origin_stop_id' => isset($item['origin_stop_id']) ? (int) $item['origin_stop_id'] : null,
                            'destination_stop_id' => isset($item['destination_stop_id']) ? (int) $item['destination_stop_id'] : null,
                            'alighting_point_id' => isset($item['alighting_point_id']) ? (int) $item['alighting_point_id'] : null,
                            'origin_lat' => isset($item['origin_lat']) ? (float) $item['origin_lat'] : null,
                            'origin_lng' => isset($item['origin_lng']) ? (float) $item['origin_lng'] : null,
                            'destination_lat' => isset($item['destination_lat']) ? (float) $item['destination_lat'] : null,
                            'destination_lng' => isset($item['destination_lng']) ? (float) $item['destination_lng'] : null,
                        ]);

                        if (! isset($groupedItems[$itemKey])) {
                            $groupedItems[$itemKey] = [
                                'item' => $item,
                                'count' => 0,
                            ];
                        }

                        $groupedItems[$itemKey]['count']++;
                    }

                    foreach ($groupedItems as $group) {
                        // Compute fare once per unique item and reserve remaining
                        // repeated quantity as one batched capacity operation.
                        $template = $this->ticketService->reserveAndPrice($group['item']);

                        $extraCount = max(0, ((int) $group['count']) - 1);
                        if ($extraCount > 0) {
                            $this->ticketService->reserveFromTemplate($template, $extraCount);
                        }

                        for ($i = 0; $i < (int) $group['count']; $i++) {
                            $reserved = $template;
                            $total += $reserved['amount'];
                            $reserved['passenger_id'] = $passengerId;
                            $reservedItems[] = $reserved;
                        }
                    }

                    Log::info('Checkout reservation stage completed', [
                        'item_count' => count($payload['items'] ?? []),
                        'payment_id' => $payment->payment_id,
                        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ]);

                    $rewardPointsApplied = 0;
                    $rewardDiscount = 0.0;

                    if ($passengerId && $pointsRequested > 0) {
                        $currentPoints = $this->rewardService->getPoints($passengerId);
                        if ($currentPoints <= 0) {
                            throw ValidationException::withMessages([
                                'reward_points_to_use' => ['No reward points are available to redeem.'],
                            ]);
                        }

                        $perPoint = RewardService::REDEMPTION_PESOS_PER_POINT;
                        $maxRedeemableByAmount = max(0, (int) floor(($total - 1) / $perPoint));
                        $rewardPointsApplied = min($pointsRequested, $currentPoints, $maxRedeemableByAmount);
                        $rewardDiscount = (float) ($rewardPointsApplied * $perPoint);

                        if ($rewardPointsApplied <= 0) {
                            throw ValidationException::withMessages([
                                'reward_points_to_use' => ['Requested reward points cannot be applied. Minimum payable amount is PHP 1.00 for online checkout.'],
                            ]);
                        }
                    }

                    $netTotal = max(0.0, round($total - $rewardDiscount, 2));

                    $remainingDiscount = $rewardDiscount;
                    foreach ($reservedItems as $idx => $reserved) {
                        $grossAmount = (float) ($reserved['amount'] ?? 0);
                        $itemDiscount = min($grossAmount, $remainingDiscount);
                        $netAmount = max(0.0, round($grossAmount - $itemDiscount, 2));
                        $remainingDiscount = max(0.0, round($remainingDiscount - $itemDiscount, 2));

                        $reservedItems[$idx]['reward_earn_amount'] = $netAmount;
                    }

                    $targetCentavos = max(100, (int) round($netTotal * 100));
                    $lineItems = [[
                        'name' => 'Smart Transit Ticket Purchase',
                        'amount' => $targetCentavos,
                        'currency' => 'PHP',
                        'quantity' => 1,
                    ]];

                    $payment->amount = $netTotal;
                    $payment->items_payload = $reservedItems;
                    // Matches PayMongo's default checkout session expiry window.
                    // Confirm against your PayMongo dashboard settings if you've
                    // customized session expiry.
                    $payment->hold_expires_at = Carbon::now()->addMinutes(15);
                    $payment->save();

                    // The points are taken here, in the same transaction as the hold and BEFORE the gateway is asked for a
                    // session, in one atomic statement. If they are gone (another checkout spent them a moment ago) the
                    // whole checkout, hold included, is undone and nobody gets a discount that was not paid for.
                    if ($passengerId && $rewardPointsApplied > 0) {
                        $taken = $this->rewardService->redeemPoints(
                            $passengerId,
                            $rewardPointsApplied,
                            'Redeemed during checkout: '.$payment->transaction_reference,
                            $payment->payment_id,
                        );

                        if (! $taken) {
                            throw ValidationException::withMessages([
                                'reward_points_to_use' => ['Those reward points are no longer available. Please try again.'],
                            ]);
                        }
                    }

                    return [
                        'payment' => $payment,
                        'line_items' => $lineItems,
                        'gross_amount' => (float) $total,
                        'reward_points_applied' => $rewardPointsApplied,
                        'reward_discount' => $rewardDiscount,
                        'net_amount' => (float) $payment->amount,
                    ];
                });
            } catch (QueryException $exception) {
                if ($identity === null || ! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                $existing = $this->paymentRepository->findByIdempotencyKey($identity['scope_hash'], $identity['key_hash']);
                if (! $existing) {
                    throw $exception;
                }

                return $this->replayOnlineCheckout($existing, $identity);
            }

            if (isset($prepared['idempotency_replay'])) {
                return $this->replayOnlineCheckout($prepared['idempotency_replay'], $identity);
            }

            /** @var Payment $payment */
            $payment = $prepared['payment'];
            $returnBaseUrl = $this->resolveCheckoutReturnBaseUrl($payload);

            try {
                $session = $this->payMongoService->createCheckoutSession(
                    $payment,
                    $prepared['line_items'],
                    $returnBaseUrl.'/checkout/success?ref='.urlencode($payment->transaction_reference),
                    $returnBaseUrl.'/checkout/cancel?ref='.urlencode($payment->transaction_reference),
                    $payload['billing_email'] ?? null,
                );

                Log::info('Checkout gateway session created', [
                    'payment_id' => $payment->payment_id,
                    'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            } catch (PayMongoCheckoutOutcomeUnknown $e) {
                $payment->forceFill(['idempotency_state' => 'unknown'])->save();
                Log::warning('Checkout session outcome is unknown; preserving the payment hold for reconciliation.', [
                    'payment_id' => $payment->payment_id,
                    'transaction_reference' => $payment->transaction_reference,
                ]);

                throw $e;
            } catch (\Throwable $e) {
                try {
                    $this->failOnlinePaymentWithReleases($payment);
                } catch (\Throwable $releaseError) {
                    Log::error('Checkout release failed after gateway error', [
                        'payment_id' => $payment->payment_id,
                        'error' => $releaseError->getMessage(),
                    ]);
                }
                if ($identity !== null) {
                    $payment->forceFill(['idempotency_state' => 'failed'])->save();
                }
                throw $e;
            }

            $replayResponse = [
                'gross_amount' => $prepared['gross_amount'],
                'reward_points_applied' => $prepared['reward_points_applied'],
                'reward_discount' => $prepared['reward_discount'],
                'net_amount' => $prepared['net_amount'],
                'checkout_url' => $session['attributes']['checkout_url'],
            ];

            $completedPayment = DB::transaction(function () use ($session, $payment, $identity, $replayResponse) {
                $freshPayment = $this->paymentRepository->findById($payment->payment_id);
                if (! $freshPayment) {
                    throw new \RuntimeException('Payment record no longer exists.');
                }

                $freshPayment->gateway_reference = $session['id'];
                $freshPayment->payment_intent_id = $session['attributes']['payment_intent']['id'] ?? null;
                if ($identity !== null) {
                    $freshPayment->idempotency_state = 'completed';
                    $freshPayment->idempotency_response = $replayResponse;
                }
                $freshPayment->save();

                return $freshPayment;
            });

            Log::info('Checkout finalized for redirect', [
                'payment_id' => $payment->payment_id,
                'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return [
                'payment' => $completedPayment,
            ] + $replayResponse;
        });
    }

    /** @return array{scope_hash: string, key_hash: string, request_hash: string}|null */
    private function idempotencyIdentity(?string $key, string $scope, array $payload): ?array
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        $normalized = $this->normalizeIdempotencyValue($payload);

        return [
            'scope_hash' => hash('sha256', $scope),
            'key_hash' => hash('sha256', $key),
            'request_hash' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    private function normalizeIdempotencyValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalizeIdempotencyValue($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeIdempotencyValue($item);
        }

        return $value;
    }

    private function replayOnlineCheckout(Payment $payment, array $identity): array
    {
        if (! hash_equals((string) $payment->idempotency_request_hash, $identity['request_hash'])) {
            throw new CheckoutIdempotencyConflict('This checkout key was already used with different details. Start a new checkout.');
        }
        if ($payment->idempotency_state !== 'completed' || ! is_array($payment->idempotency_response)) {
            if ($payment->idempotency_state === 'settled') {
                throw new CheckoutIdempotencyConflict('This checkout has been settled. Check its payment status before starting another checkout.');
            }
            if ($this->releaseIfAttemptIsOver($payment)) {
                throw new CheckoutIdempotencyConflict('Your last attempt did not go through, and its seats were released. Try again.', retryWithNewKey: true);
            }
            throw new CheckoutIdempotencyConflict('A checkout with this key is still processing or did not complete. Check its status before trying again.');
        }

        return ['payment' => $this->paymentRepository->findById($payment->payment_id) ?? $payment]
            + $payment->idempotency_response;
    }

    /**
     * §12 (checkout "lockout"): a retry of the same checkout used to be refused until the 15-minute hold ran out.
     *  - `failed`: the gateway refused it and its seats are already released; nothing is waiting.
     *  - `unknown`: the gateway did not answer in time, so no checkout link ever reached the passenger and nobody can
     *    pay that session. After payments.unknown_outcome_release_minutes it is released like a failed one.
     * A checkout whose link was delivered (`completed`) keeps its 15-minute hold: the passenger may still be paying.
     */
    private function releaseIfAttemptIsOver(Payment $payment): bool
    {
        if ($payment->idempotency_state === 'failed') {
            return true;
        }

        $releaseAfter = (int) config('payments.unknown_outcome_release_minutes', 2);
        $startedAt = $payment->created_at ?? $payment->payment_created;
        if ($payment->idempotency_state !== 'unknown' || ! $startedAt || Carbon::parse($startedAt)->gt(now()->subMinutes($releaseAfter))) {
            return false;
        }

        $this->failOnlinePaymentWithReleases($payment);
        Log::info('Checkout with an unknown gateway outcome released on retry.', ['payment_id' => $payment->payment_id]);

        return true;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['23000', '23505'], true) || $driverCode === 19;
    }

    private function resolveCheckoutReturnBaseUrl(array $payload): string
    {
        $candidate = $payload['return_base_url'] ?? null;
        $defaultBaseUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

        if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
            $scheme = parse_url($candidate, PHP_URL_SCHEME);
            if (is_string($scheme)
                && in_array(Str::lower($scheme), ['http', 'https'], true)
                && $this->isAllowedCheckoutReturnUrl($candidate, $defaultBaseUrl)) {
                return rtrim($candidate, '/');
            }
        }

        return $defaultBaseUrl;
    }

    private function isAllowedCheckoutReturnUrl(string $candidate, string $defaultBaseUrl): bool
    {
        $candidateHost = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        if ($candidateHost === '') {
            return false;
        }

        $allowedHosts = [];
        $defaultHost = strtolower((string) parse_url($defaultBaseUrl, PHP_URL_HOST));
        if ($defaultHost !== '') {
            $allowedHosts[] = $defaultHost;
        }

        if (app()->environment(['local', 'testing'])) {
            $allowedHosts = array_merge($allowedHosts, ['localhost', '127.0.0.1']);
        }

        if (in_array($candidateHost, array_unique($allowedHosts), true)) {
            return true;
        }

        // Allow Vercel preview/production URLs when configured frontend host is on Vercel.
        if ($defaultHost !== '' && str_ends_with($defaultHost, '.vercel.app')) {
            return str_ends_with($candidateHost, '.vercel.app');
        }

        return false;
    }

    /**
     * $payload = ['items' => [...]]
     * $payload['items'][*] = [...same shapes as above, plus 'passenger_id' nullable]
     *
     * Onsite/cash sales are confirmed instantly, so tickets are still
     * created immediately here (no pending window to defer for).
     */
    public function checkoutOnsite(int $conductorCompanyUserId, array $payload, ?string $idempotencyKey = null): array
    {
        $identity = $this->idempotencyIdentity($idempotencyKey, 'conductor:'.$conductorCompanyUserId, $payload);

        try {
            $result = RlsContext::withBypass(fn () => DB::transaction(function () use ($conductorCompanyUserId, $payload, $identity) {
                if ($identity !== null) {
                    $existing = $this->paymentRepository->findByIdempotencyKey($identity['scope_hash'], $identity['key_hash'], true);
                    if ($existing) {
                        return ['idempotency_replay' => $existing];
                    }
                }

                $payment = $this->paymentRepository->create([
                    'amount' => 0,
                    'transaction_reference' => 'TXN-'.strtoupper(bin2hex(random_bytes(8))),
                    'payment_method' => 'cash',
                    'payment_channel' => 'onsite',
                    'status' => 'paid',
                    'is_valid' => true,
                    'idempotency_scope_hash' => $identity['scope_hash'] ?? null,
                    'idempotency_key_hash' => $identity['key_hash'] ?? null,
                    'idempotency_request_hash' => $identity['request_hash'] ?? null,
                    'idempotency_state' => $identity !== null ? 'processing' : null,
                ]);

                $issuedAt = Carbon::now('UTC');

                $this->onsitePaymentRepository->create([
                    'payment_id' => $payment->payment_id,
                    'conductor_id' => $conductorCompanyUserId,
                    // Batch 29: the Conductor shift this cash was collected in (a cash sale needs an open shift).
                    'conductor_shift_log_id' => $this->shiftCash->openShiftId($conductorCompanyUserId),
                    // Batch 27: explicit server time in UTC (the stored value is UTC).
                    'issued_at' => $issuedAt->format('Y-m-d H:i:s'),
                ]);

                $total = 0;
                $tickets = [];

                foreach ($payload['items'] as $item) {
                    $passengerId = $item['passenger_id'] ?? null;
                    // Set here, never read from the request: only the cash flow may sell while the bus is moving.
                    $item['channel'] = 'onsite';
                    $ticket = $this->ticketService->issueTicket($item, $passengerId, $payment->payment_id);
                    $total += $ticket->amount;
                    $tickets[] = $ticket;
                }

                $payment->amount = $total;
                if ($identity !== null) {
                    $payment->idempotency_state = 'completed';
                    $payment->idempotency_response = ['issued_at' => $issuedAt->toIso8601ZuluString()];
                }
                $payment->save();

                return [
                    'payment' => $payment,
                    'tickets' => $tickets,
                    // ISO 8601 with Z: clients convert to Asia/Manila for display.
                    'issued_at' => $issuedAt->toIso8601ZuluString(),
                ];
            }));
        } catch (QueryException $exception) {
            if ($identity === null || ! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->paymentRepository->findByIdempotencyKey($identity['scope_hash'], $identity['key_hash']);
            if (! $existing) {
                throw $exception;
            }

            return $this->replayOnsiteCheckout($existing, $identity);
        }

        if (isset($result['idempotency_replay'])) {
            return $this->replayOnsiteCheckout($result['idempotency_replay'], $identity);
        }

        return $result;
    }

    /**
     * The fare a cash sale of this item would charge right now, for the Conductor to read out before selling. It prices
     * through the same method the sale uses (same rules, same fare rule), and reserves and records nothing.
     *
     * @return array{amount: float, distance_km: float|null, seat_type: string}
     */
    public function quoteOnsite(array $item): array
    {
        // Set here, never read from the request, exactly as the sale does.
        $item['channel'] = 'onsite';
        $priced = $this->ticketService->priceItem($item);

        return [
            'amount' => round((float) $priced['amount'], 2),
            'distance_km' => isset($priced['distance_km']) ? (float) $priced['distance_km'] : null,
            'seat_type' => (string) $priced['seat_type'],
            // 2026-10-27: with an onsite discount, the fare before it and the discount (null without one).
            'original_amount' => isset($priced['original_amount']) ? (float) $priced['original_amount'] : null,
            'discount_amount' => isset($priced['discount_amount']) ? (float) $priced['discount_amount'] : null,
            'discount_label' => $priced['discount_label'] ?? null,
        ];
    }

    private function replayOnsiteCheckout(Payment $payment, array $identity): array
    {
        if (! hash_equals((string) $payment->idempotency_request_hash, $identity['request_hash'])) {
            throw new CheckoutIdempotencyConflict('This checkout key was already used with different details. Start a new checkout.');
        }
        if ($payment->idempotency_state !== 'completed' || ! is_array($payment->idempotency_response)) {
            throw new CheckoutIdempotencyConflict('A cash checkout with this key is already processing. Check the receipt before retrying.');
        }

        $payment = $this->paymentRepository->findByIdempotencyKey($identity['scope_hash'], $identity['key_hash']) ?? $payment;

        return [
            'payment' => $payment,
            'tickets' => $payment->tickets,
            'issued_at' => $payment->idempotency_response['issued_at'],
            'idempotency_replayed' => true,
        ];
    }

    /**
     * Called by PayMongoController::handleCheckoutPaid. Confirms the payment
     * and finalizes every reserved item in items_payload into a real ticket
     * row, atomically — if any item fails to finalize, the whole thing rolls
     * back (including the status change), so the webhook can be safely
     * retried by PayMongo instead of leaving a half-issued purchase.
     *
     * Idempotent: safe to call again on webhook redelivery. One delivery at a time per payment (row lock), so two
     * deliveries at the same instant cannot both issue tickets.
     *
     * A payment that arrives AFTER its hold was released (the hold expired, or an earlier attempt in the same checkout
     * failed and the customer then paid) is honoured only if its seats are still free, the trip is still taking
     * bookings and the reward points it used can be taken again. Otherwise no ticket is issued and the payment is
     * marked 'needs_refund' (the money was taken, so a person must refund it) and the Operator is told.
     */
    public function finalizeOnlinePayment(Payment $payment): void
    {
        RlsContext::withBypass(function () use ($payment) {
            $outcome = DB::transaction(function () use ($payment) {
                $locked = Payment::whereKey($payment->payment_id)->lockForUpdate()->first() ?? $payment;

                if ($locked->tickets()->exists()) {
                    Log::info('PaymentService: tickets already issued for this payment, skipping.', ['payment_id' => $locked->payment_id]);
                    $this->confirmOnlinePayment($locked->payment_id);
                    $this->settleUnknownCheckout($locked);

                    return 'already_issued';
                }

                if ($locked->status === 'needs_refund') {
                    $this->settleUnknownCheckout($locked);

                    return 'needs_refund'; // already flagged: a redelivery must not issue tickets now
                }

                if (in_array($locked->status, ['expired', 'failed'], true) && ! $this->reclaimReleasedHold($locked)) {
                    $this->paymentRepository->markStatus($locked->payment_id, 'needs_refund', false);
                    $this->settleUnknownCheckout($locked);

                    return 'needs_refund';
                }

                $this->confirmOnlinePayment($locked->payment_id);
                $this->settleUnknownCheckout($locked);

                foreach ($locked->items_payload ?? [] as $reservedItem) {
                    $passengerId = $reservedItem['passenger_id'] ?? null;
                    $this->ticketService->finalizeTicket($reservedItem, $passengerId, $locked->payment_id);
                }

                return 'issued';
            });

            if ($outcome === 'already_issued') {
                return;
            }

            if ($outcome === 'needs_refund') {
                Log::error('PaymentService: a payment arrived after its hold was released and could not be honoured; it needs a refund.', [
                    'payment_id' => $payment->payment_id,
                    'transaction_reference' => $payment->transaction_reference,
                ]);
                $this->notifyOperators(
                    $payment,
                    'Payment needs a refund',
                    'A payment (transaction '.$payment->transaction_reference.') arrived after its seats were released and no ticket was issued. Please refund it.',
                    'payment_needs_refund',
                );

                return;
            }

            // Batch 15, Item 3: push the relevant Operator(s) so their
            // dashboard can react (silent refresh) instead of waiting on
            // the next poll cycle. Fire-and-forget — never let a push
            // failure affect the payment/ticket transaction above, which
            // has already committed by this point.
            $this->notifyOperatorsOfPayment($payment);

            // The buyer gets the tracking link of each ticket by email (fail-soft and queued: it never delays or undoes
            // the payment, and it carries no QR code).
            $this->trackingMail->sendForPayment($payment);
            // Batch 28 Stage 3, item 6: a payment that was still pending when the operator cancelled the trip is confirmed
            // late; its tickets must not be valid for a trip that will not run, so they join those awaiting a choice.
            $this->tripCancellations->flagTicketsOfCancelledTrips($payment->fresh());
        });
    }

    /**
     * Takes the seats (and the reward points) of a payment whose hold was released, again. All or nothing: the
     * attempt runs in its own savepoint, so a refusal leaves nothing half-taken.
     */
    private function reclaimReleasedHold(Payment $payment): bool
    {
        try {
            DB::transaction(function () use ($payment) {
                foreach ($payment->items_payload ?? [] as $reservedItem) {
                    $trip = $this->tripRepository->findById((int) ($reservedItem['trip_id'] ?? 0));
                    if (! $trip || ! in_array($trip->status, ['scheduled', 'delayed', 'boarding'], true)) {
                        throw ValidationException::withMessages(['trip' => ['This trip is not accepting tickets.']]);
                    }

                    // Throws "This trip is full" when the seat or standing slot is gone.
                    $this->ticketService->reserveFromTemplate($reservedItem);
                }

                if (! $this->rewardService->reclaimRedeemedPoints($payment->payment_id)) {
                    throw ValidationException::withMessages(['reward_points_to_use' => ['The reward points are no longer available.']]);
                }
            });
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * Resolves the unique set of Operators who own the trip(s) covered by
     * this payment (a multi-item cart can span more than one trip/fleet)
     * and sends each one a push notification.
     */
    private function notifyOperatorsOfPayment(Payment $payment): void
    {
        $this->notifyOperators(
            $payment,
            'Payment Confirmed',
            "A passenger payment of \u{20B1}".number_format((float) $payment->amount, 2)." was just confirmed (transaction {$payment->transaction_reference}).",
            'payment_confirmed',
        );
    }

    private function notifyOperators(Payment $payment, string $title, string $body, string $type): void
    {
        $tripIds = collect($payment->items_payload ?? [])
            ->pluck('trip_id')
            ->filter()
            ->unique();

        if ($tripIds->isEmpty()) {
            return;
        }

        $notifiedUserIds = [];
        foreach ($tripIds as $tripId) {
            $trip = $this->tripRepository->findById((int) $tripId);
            $operatorUser = $trip?->companyUser?->user;
            if (! $operatorUser || in_array($operatorUser->user_id, $notifiedUserIds, true)) {
                continue;
            }

            $this->fcmService->sendToUser(
                $operatorUser,
                $title,
                $body,
                ['type' => $type, 'payment_id' => $payment->payment_id],
            );
            $notifiedUserIds[] = $operatorUser->user_id;
        }
    }

    /**
     * Called by PayMongoController::handlePaymentFailed. Marks the payment
     * failed and releases every reserved item's held seat/standing capacity
     * back to the trip. Idempotent.
     */
    public function failOnlinePaymentWithReleases(Payment $payment): void
    {
        RlsContext::withBypass(function () use ($payment) {
            if ($payment->status === 'failed') {
                Log::info('PaymentService: payment already marked failed, skipping duplicate release.', ['payment_id' => $payment->payment_id]);

                return;
            }

            // A late/out-of-order failure event must never undo a confirmed payment.
            if ($payment->status === 'paid') {
                Log::warning('PaymentService: ignoring failure event for an already-paid payment.', ['payment_id' => $payment->payment_id]);

                return;
            }

            DB::transaction(function () use ($payment) {
                $this->failOnlinePayment($payment->payment_id);

                foreach ($payment->items_payload ?? [] as $reservedItem) {
                    $this->ticketService->releaseTicketHold($reservedItem);
                }

                // The points this checkout took come back with the seats (once, however many times this runs).
                $this->rewardService->returnRedeemedPoints($payment->payment_id);

                if ($payment->idempotency_state === 'unknown') {
                    Payment::whereKey($payment->payment_id)
                        ->where('idempotency_state', 'unknown')
                        ->update(['idempotency_state' => 'failed']);
                }
            });

            // Notifications audit follow-up: let the Passenger know their
            // payment didn't go through, since their held seat/standing
            // capacity was just released back to the trip.
            $this->notifyPassengerOfPaymentFailure($payment);
        });
    }

    /**
     * Resolves the passenger who attempted this payment (guest checkouts
     * have no passenger_id and are skipped — they have no account to push
     * to) and sends a failure notification.
     */
    private function notifyPassengerOfPaymentFailure(Payment $payment): void
    {
        $passengerId = collect($payment->items_payload ?? [])
            ->pluck('passenger_id')
            ->filter()
            ->first();

        if (! $passengerId) {
            return;
        }

        $passengerUser = $this->passengerRepository->findById((int) $passengerId)?->user;
        if (! $passengerUser) {
            return;
        }

        $this->fcmService->sendToUser(
            $passengerUser,
            'Payment Failed',
            "Your payment of \u{20B1}".number_format((float) $payment->amount, 2).' could not be completed. Your reserved seat has been released — please try booking again.',
            ['type' => 'payment_failed', 'payment_id' => $payment->payment_id],
        );
    }

    public function findById(int $paymentId)
    {
        return $this->paymentRepository->findById($paymentId);
    }

    public function findByGatewayReference(string $gatewayReference)
    {
        return $this->paymentRepository->findByGatewayReference($gatewayReference);
    }

    public function findByPaymentIntentId(string $paymentIntentId)
    {
        return $this->paymentRepository->findByPaymentIntentId($paymentIntentId);
    }

    private function settleUnknownCheckout(Payment $payment): void
    {
        if ($payment->idempotency_state !== 'unknown') {
            return;
        }

        $payment->idempotency_state = 'settled';
        $payment->save();
    }

    public function confirmOnlinePayment(int $paymentId): bool
    {
        return $this->paymentRepository->markStatus($paymentId, 'paid', true);
    }

    public function failOnlinePayment(int $paymentId): bool
    {
        return $this->paymentRepository->markStatus($paymentId, 'failed', false);
    }

    public function getPassengerHistoryFromPayments(int $passengerId, int $page = 1, int $perPage = 50): object
    {
        return $this->paymentRepository->findPassengerHistoryFromPayments($passengerId, $perPage, $page);
    }
}
