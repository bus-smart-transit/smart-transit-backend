<?php

// One passenger's checkout in the last-seat race (LastSeatRaceTest). Each worker is its own PHP process with its own
// database connection, waits at a shared barrier, then checks out at the same moment as the other worker.

use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['services.paymongo.secret_key' => 'sk_test_race']);
// The gateway answers slowly, so the winner still holds its transaction-free hold while the loser checks capacity.
Http::fake(['api.paymongo.com/*' => function () {
    usleep(300_000);

    return Http::response(['data' => ['id' => 'cs_race_'.Str::random(12), 'attributes' => ['checkout_url' => 'https://pay.example/race', 'payment_intent' => ['id' => 'pi_race_'.Str::random(8)]]]]);
}]);

file_put_contents($input['ready_path'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($input['go_path']) && microtime(true) < $deadline) {
    usleep(5_000);
}

try {
    $result = app(PaymentService::class)->checkoutOnline($input['passenger_id'], null, $input['payload'], $input['idempotency_key']);
    $output = ['outcome' => 'reserved', 'payment_id' => $result['payment']->payment_id];
} catch (ValidationException $exception) {
    $output = ['outcome' => 'refused', 'message' => (string) collect($exception->errors())->flatten()->first()];
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR).PHP_EOL);
