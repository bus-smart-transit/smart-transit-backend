<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Repositories\SmsLogRepository;
use App\Services\Sms\IprogSmsGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Batch 64: sends one recorded text (SmsService made the sms_logs row). Tried once: a retry could charge a second
 * credit for a text IPROG already accepted, so a failure is recorded with IPROG's answer instead.
 */
class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $logId) {}

    public function handle(SmsLogRepository $logs, IprogSmsGateway $gateway): void
    {
        $log = $logs->find($this->logId);
        if (! $log || $log->status !== SmsLog::QUEUED || ! $log->phone) {
            return;
        }

        try {
            $result = $gateway->send($log->phone, $log->message);
        } catch (\Throwable $e) {
            // Never leave a text "queued" for ever: whatever went wrong is recorded as the reason.
            $result = ['ok' => false, 'message_id' => null, 'error' => mb_substr('Could not send: '.$e->getMessage(), 0, 255)];
        }
        $logs->update($log, $result['ok']
            ? ['status' => SmsLog::SENT, 'provider_message_id' => $result['message_id'], 'sent_at' => now(), 'error' => null]
            : ['status' => SmsLog::FAILED, 'error' => $result['error']]);
    }
}
