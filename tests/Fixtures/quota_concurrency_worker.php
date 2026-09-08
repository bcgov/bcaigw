<?php

use App\Services\RedisQuotaCounterStore;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $applicationKey, $reservationId, $requestLimit] = $argv;
$now = now('UTC');
$result = (new RedisQuotaCounterStore)->reserve([
    'application_key' => $applicationKey,
    'reservation_id' => $reservationId,
    'minute_window' => $now->format('YmdHi'),
    'day_window' => $now->format('Ymd'),
    'month_window' => $now->format('Ym'),
    'request_limit' => (int) $requestLimit,
    'token_rate_limit' => 0,
    'daily_token_limit' => 0,
    'monthly_token_limit' => 0,
    'daily_cost_limit' => 0,
    'monthly_cost_limit' => 0,
    'reserved_tokens' => 1,
    'reserved_cost' => 1,
    'minute_ttl' => 120,
    'day_ttl' => 300,
    'month_ttl' => 300,
    'retry_after' => 30,
    'day_retry_after' => 300,
    'month_retry_after' => 300,
]);

echo $result->allowed ? 'allowed' : $result->limitCode;
