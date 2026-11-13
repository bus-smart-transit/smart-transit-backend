<?php

namespace App\Console\Commands;

use App\Repositories\SmsLogRepository;
use App\Services\Sms\IprogSmsGateway;
use App\Services\Sms\SmsService;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Batch 64: the text message tools for the Super Admin (on the server):
 *   php artisan sms test 09171234567          one test text through the normal path (costs one credit)
 *   php artisan sms credits                   the IPROG credits left
 *   php artisan sms log                       the last 20 texts and what happened to each
 */
class SmsTools extends Command
{
    protected $signature = 'sms {action : test, credits or log} {phone? : for test, a Philippine mobile number} {--message= : for test, the text}';

    protected $description = 'Batch 64: send a test text, show the IPROG credits left, or list the last texts sent.';

    public function handle(SmsService $sms, IprogSmsGateway $gateway, SmsLogRepository $logs): int
    {
        return match ($this->argument('action')) {
            'test' => $this->test($sms),
            'credits' => $this->credits($gateway),
            'log' => $this->log($logs),
            default => $this->usage(),
        };
    }

    private function usage(): int
    {
        $this->error('Use: sms test <phone>, sms credits or sms log.');

        return self::FAILURE;
    }

    private function test(SmsService $sms): int
    {
        if (! config('sms.enabled')) {
            $this->error('SMS is off. Set SMS_ENABLED=true (and IPROGSMS_API_TOKEN) in .env, then restart the backend.');

            return self::FAILURE;
        }
        $log = $sms->send('test', (string) $this->argument('phone'), (string) ($this->option('message') ?: 'SmartTransit test message. If you got this, texts work.'));
        $log?->refresh();
        $this->line('Status: '.($log?->status ?? 'not sent').($log?->error ? ' - '.$log->error : ''));
        $this->line('To: '.PhoneNumber::mask($log?->phone));

        return $log?->status === 'skipped' ? self::FAILURE : self::SUCCESS;
    }

    private function credits(IprogSmsGateway $gateway): int
    {
        $credits = $gateway->credits();
        $this->line($credits === null ? 'Credits: Not provided (no token, or IPROG did not answer).' : 'Credits left: '.$credits);

        return $credits === null ? self::FAILURE : self::SUCCESS;
    }

    private function log(SmsLogRepository $logs): int
    {
        $this->table(['When', 'Kind', 'To', 'Status', 'Problem'], $logs->latest()->map(fn ($row) => [
            $row->created_at?->timezone('Asia/Manila')->format('Y-m-d H:i'),
            $row->event,
            PhoneNumber::mask($row->phone),
            $row->status,
            $row->error ?? '',
        ])->all());

        return self::SUCCESS;
    }
}
