<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @mixin IdeHelperPayment
 *
 * @property int $payment_id
 * @property float $amount
 * @property Carbon $payment_created
 * @property string $transaction_reference
 * @property string $payment_method
 * @property string $payment_channel
 * @property string $status
 * @property string $payment_uuid
 * @property bool $is_valid
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $gateway_reference
 * @property string|null $guest_email
 * @property string|null $payment_intent_id
 * @property array<array-key, mixed>|null $items_payload
 * @property Carbon|null $hold_expires_at
 * @property-read OnlinePayment|null $onlinePayment
 * @property-read OnsitePayment|null $onsitePayment
 * @property-read Collection<int, Ticket> $tickets
 * @property-read int|null $tickets_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereGatewayReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereGuestEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereHoldExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereIsValid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereItemsPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentCreated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentIntentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaymentUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereTransactionReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Payment extends Model
{
    protected $table = 'payments';

    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'amount',
        'payment_created',
        'transaction_reference',
        'payment_method',
        'payment_channel',
        'status',
        'payment_uuid',
        'is_valid',
        'guest_email',
        'gateway_reference',
        'payment_intent_id',
        'gateway_payment_id',
        'items_payload',
        'hold_expires_at',
        'refunded_at',
        'refund_note',
        'refunded_by_user_id',
        'idempotency_scope_hash',
        'idempotency_key_hash',
        'idempotency_request_hash',
        'idempotency_state',
        'idempotency_response',
        'email_group_qr',
    ];

    protected $hidden = [
        'idempotency_scope_hash',
        'idempotency_key_hash',
        'idempotency_request_hash',
        'idempotency_state',
        'idempotency_response',
    ];

    protected function casts(): array
    {
        return [
            'payment_created' => 'datetime',
            'hold_expires_at' => 'datetime',
            'refunded_at' => 'datetime',
            // Stores the reserved items (trip_id, seat_type, stop ids OR
            // coordinates, fare_rule_id, distance_km, amount) locked in at
            // checkout time, so the webhook can create the actual ticket
            // rows later without re-running any pricing logic.
            'items_payload' => 'array',
            'idempotency_response' => 'array',
            'email_group_qr' => 'boolean',
        ];
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'payment_id', 'payment_id');
    }

    public function onlinePayment()
    {
        return $this->hasOne(OnlinePayment::class, 'payment_id', 'payment_id');
    }

    public function onsitePayment()
    {
        return $this->hasOne(OnsitePayment::class, 'payment_id', 'payment_id');
    }
}
