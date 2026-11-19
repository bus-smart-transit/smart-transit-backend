<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 64: one text message and what happened to it (SmsService writes it, SendSms finishes it).
 *
 * @property int $id
 * @property string $event
 * @property string|null $phone
 * @property string $message
 * @property string $status
 * @property string|null $provider_message_id
 * @property string|null $error
 */
class SmsLog extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'event', 'phone', 'message', 'status', 'provider_message_id', 'error', 'subject_type', 'subject_id', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
