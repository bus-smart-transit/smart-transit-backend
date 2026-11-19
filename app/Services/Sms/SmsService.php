<?php

namespace App\Services\Sms;

use App\Jobs\SendSms;
use App\Models\SmsLog;
use App\Repositories\SmsLogRepository;
use App\Support\PhoneNumber;

/**
 * Batch 64 (user, 2026-11-13): ONE owner for sending a text message. Every text goes through send():
 *  - nothing happens while SMS is off (sms.enabled) or that kind of text is switched off (sms.events.<event>);
 *  - a number that is not a Philippine mobile is recorded as skipped, never sent;
 *  - sms.intercept_to sends every text to one test number instead;
 *  - the text is cut to sms.max_length (one credit) and sent from the queue after the database commit, so a slow or
 *    failing gateway never delays or undoes a payment, a login or a cancellation.
 * What happened is in sms_logs. Message wording lives in SmsNotifier.
 */
class SmsService
{
    public function __construct(private SmsLogRepository $logs) {}

    public static function enabledFor(string $event): bool
    {
        return (bool) config('sms.enabled') && (bool) config("sms.events.{$event}", false);
    }

    public function send(string $event, ?string $phone, string $message, ?string $subjectType = null, ?int $subjectId = null): ?SmsLog
    {
        if (! self::enabledFor($event)) {
            return null;
        }

        $to = PhoneNumber::normalize((string) (config('sms.intercept_to') ?: $phone));
        $text = mb_substr(trim(preg_replace('/\s+/', ' ', $message)), 0, (int) config('sms.max_length', 160));
        $base = ['event' => $event, 'message' => $text, 'subject_type' => $subjectType, 'subject_id' => $subjectId];

        if ($to === null || ! str_starts_with($to, '+639')) {
            return $this->logs->create($base + ['phone' => null, 'status' => SmsLog::SKIPPED, 'error' => 'No Philippine mobile number on record.']);
        }

        // Batch 66: some texts say the same thing every time, so one a day per phone is enough (sms.once_per_day).
        $startOfDay = now('Asia/Manila')->startOfDay()->setTimezone(config('app.timezone'));
        if (in_array($event, (array) config('sms.once_per_day', []), true) && $this->logs->sentSince($event, $to, $startOfDay)) {
            return $this->logs->create($base + ['phone' => $to, 'status' => SmsLog::SKIPPED, 'error' => 'Already texted today.']);
        }

        $log = $this->logs->create($base + ['phone' => $to, 'status' => SmsLog::QUEUED]);
        SendSms::dispatch($log->id)->afterCommit();

        return $log;
    }
}
