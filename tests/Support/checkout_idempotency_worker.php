<?php

use App\Exceptions\CheckoutIdempotencyConflict;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

$basePath = dirname(__DIR__, 2);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['services.paymongo.secret_key' => 'sk_test_concurrency']);
Http::fake(['api.paymongo.com/*' => function () {
    usleep(500_000);

    return Http::response([
        'data' => [
            'id' => 'cs_concurrent_test',
            'attributes' => [
                'checkout_url' => 'https://pay.example/concurrent',
                'payment_intent' => ['id' => 'pi_concurrent_test'],
            ],
        ],
    ]);
}]);

file_put_contents($input['ready_path'], 'ready');
$deadline = microtime(true) + 20;
while (! is_file($input['go_path']) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    $result = app(PaymentService::class)->checkoutOnline(
        $input['passenger_id'],
        null,
        $input['payload'],
        $input['idempotency_key'],
    );
    $output = [
        'outcome' => 'success',
        'payment_id' => $result['payment']->payment_id,
        'provider_calls' => Http::recorded()->count(),
    ];
} catch (CheckoutIdempotencyConflict) {
    $output = ['outcome' => 'conflict', 'provider_calls' => Http::recorded()->count()];
} catch (Throwable $exception) {
    $output = ['outcome' => 'error', 'error_type' => $exception::class];
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}

fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR).PHP_EOL);
