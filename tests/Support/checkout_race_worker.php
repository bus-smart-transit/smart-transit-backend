<?php

// One passenger's payment confirmation in the last-seat race (LastSeatRaceTest). Each worker is its own PHP process with
// its own database connection, waits at a shared barrier, then confirms its payment at the same moment as the other.
// Batch 49 item 1: seats are taken only when the payment is confirmed, so that is where the race happens now.

use App\Jobs\RefundUnfulfilledPayment;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
// The automatic refund of the loser is not part of the race (and must never call PayMongo here).
Bus::fake([RefundUnfulfilledPayment::class]);

file_put_contents($input['ready_path'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($input['go_path']) && microtime(true) < $deadline) {
    usleep(5_000);
}

try {
    $payment = Payment::findOrFail($input['payment_id']);
    app(PaymentService::class)->finalizeOnlinePayment($payment);
    $output = ['outcome' => $payment->fresh()->status, 'payment_id' => $payment->payment_id];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR).PHP_EOL);
