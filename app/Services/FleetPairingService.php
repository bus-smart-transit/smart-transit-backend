<?php

namespace App\Services;

use App\Repositories\FleetDailyPinRepository;
use App\Models\FleetDailyPin;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FleetPairingService
{
    public function __construct(
        private FleetDailyPinRepository $pinRepository,
    ) {}

    // ── Token generation ────────────────────────────────────────────────────

    /**
     * Generate a signed pairing token for a staff member.
     * Token encodes: user_id, role, fleet_id, trip_id, date, expiry.
     * Signed with HMAC-SHA256 using APP_KEY — tamper-evident and non-reusable.
     */
    public function generateToken(int $userId, string $role, int $tripId, int $fleetId): string
    {
        $payload = $this->encodePayload([
            'uid'  => $userId,
            'role' => $role,
            'tid'  => $tripId,
            'fid'  => $fleetId,
            'date' => Carbon::today()->toDateString(),
            'exp'  => Carbon::now()->endOfDay()->timestamp,
        ]);

        return $payload . '.' . $this->sign($payload);
    }

    // ── Pairing validation ───────────────────────────────────────────────────

    /**
     * Validate a scanned partner token and confirm the pairing if all checks pass.
     *
     * Checks (in order):
     *   1. HMAC signature valid
     *   2. Token not expired
     *   3. Token date = today
     *   4. Scanned role != scanner role (no same-role pairing)
     *   5. Fleet matches scanner's fleet
     *   6. Trip matches scanner's trip
     *   7. Scanned user_id matches the operator's assignment record for that trip
     */
    public function validateAndPair(
        string $scannedToken,
        int    $scannerUserId,
        string $scannerRole,
        int    $scannerTripId,
        int    $scannerFleetId,
    ): FleetDailyPin {
        // 1 & 2. Decode + verify signature
        $payload = $this->decodeToken($scannedToken);

        // 3. Expiry
        if (($payload['exp'] ?? 0) < now()->timestamp) {
            $this->rejectWith('token', 'Pairing token has expired. Ask your partner to refresh their QR code.');
        }

        // 4. Date guard
        if (($payload['date'] ?? '') !== Carbon::today()->toDateString()) {
            $this->rejectWith('token', 'Token is for a different date. Pairing tokens are valid for the current shift only.');
        }

        // 5. No same-role pairing
        $scannedRole = $payload['role'] ?? '';
        if ($scannedRole === $scannerRole) {
            $this->rejectWith('token', 'You cannot pair with someone of the same role.');
        }

        // 6. Fleet must match
        if ((int) ($payload['fid'] ?? 0) !== $scannerFleetId) {
            $this->rejectWith('token', 'Wrong fleet. You and your partner must be assigned to the same fleet today.');
        }

        // 7. Trip must match
        if ((int) ($payload['tid'] ?? 0) !== $scannerTripId) {
            $this->rejectWith('token', 'Wrong trip. You and your partner are not on the same trip assignment.');
        }

        // 8. Identity check against operator's assignment record (live DB check)
        $pin = $this->pinRepository->findTodayByTrip($scannerTripId);
        if (!$pin) {
            $this->rejectWith('token', 'No pairing record found for this trip today. Ask your operator to confirm assignments.');
        }

        $scannedUserId = (int) ($payload['uid'] ?? 0);

        if ($scannerRole === 'driver') {
            // Driver scanned conductor's QR — must match assigned conductor
            if ($pin->conductor_id !== $scannedUserId) {
                $this->rejectWith('token', 'You are not paired with this conductor. Contact your operator to update the assignment.');
            }
        } else {
            // Conductor scanned driver's QR — must match assigned driver
            if ($pin->driver_id !== $scannedUserId) {
                $this->rejectWith('token', 'You are not paired with this driver. Contact your operator to update the assignment.');
            }
        }

        // ✅ All checks passed — confirm pairing
        $pin->paired_at             = now();
        $pin->driver_verified_at    ??= now();
        $pin->conductor_verified_at ??= now();
        $pin->save();

        return $pin->fresh();
    }

    // ── Status check ─────────────────────────────────────────────────────────

    /**
     * Return the pairing status for a given trip (live DB check, no local cache trusted).
     */
    public function getPairingStatus(int $tripId): array
    {
        $pin = $this->pinRepository->findTodayByTrip($tripId);

        $paired = $pin !== null
            && $pin->paired_at !== null
            && Carbon::parse($pin->paired_at)->isToday();

        return [
            'paired'     => $paired,
            'paired_at'  => $paired ? Carbon::parse($pin->paired_at)->toIso8601String() : null,
            'expires_at' => Carbon::today()->endOfDay()->toIso8601String(),
            'reason'     => $paired
                ? null
                : ($pin ? 'QR pairing not yet completed. Scan your partner\'s QR code.' : 'No assignment record found for today.'),
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function decodeToken(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            $this->rejectWith('token', 'Invalid token format. Use the QR code or paste the token exactly.');
        }

        [$payloadB64, $sigB64] = $parts;

        if (!hash_equals($this->sign($payloadB64), $sigB64)) {
            $this->rejectWith('token', 'Invalid token signature. The QR code may have been tampered with or is from a different system.');
        }

        $decoded = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        if (!is_array($decoded)) {
            $this->rejectWith('token', 'Malformed token payload.');
        }

        return $decoded;
    }

    private function sign(string $payload): string
    {
        $key = config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    }

    private function encodePayload(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** @throws ValidationException */
    private function rejectWith(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
