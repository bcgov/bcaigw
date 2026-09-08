<?php

namespace App\Services;

use App\Contracts\QuotaCounterStore;
use App\DTO\QuotaStoreResult;
use App\Exceptions\QuotaStoreUnavailable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Predis\Connection\ConnectionException;
use RedisException;

final class RedisQuotaCounterStore implements QuotaCounterStore
{
    private const RESERVE_SCRIPT = <<<'LUA'
        if redis.call('EXISTS', KEYS[7]) == 1 then
            return {0, 7, 1, -1, -1}
        end
        local amounts = {1, tonumber(ARGV[7]), tonumber(ARGV[8]), tonumber(ARGV[8]), tonumber(ARGV[9]), tonumber(ARGV[9])}
        local limits = {tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3]), tonumber(ARGV[4]), tonumber(ARGV[5]), tonumber(ARGV[6])}
        for i = 1, 6 do
            local current = tonumber(redis.call('GET', KEYS[i]) or '0')
            if limits[i] > 0 and current + amounts[i] > limits[i] then
                local retries = {tonumber(ARGV[13]), tonumber(ARGV[13]), tonumber(ARGV[14]), tonumber(ARGV[15]), tonumber(ARGV[14]), tonumber(ARGV[15])}
                return {0, i, retries[i], limits[1] > 0 and math.max(0, limits[1] - tonumber(redis.call('GET', KEYS[1]) or '0')) or -1, limits[2] > 0 and math.max(0, limits[2] - tonumber(redis.call('GET', KEYS[2]) or '0')) or -1}
            end
        end
        redis.call('SET', KEYS[7], 'reserved', 'EX', tonumber(ARGV[12]), 'NX')
        local ttls = {tonumber(ARGV[10]), tonumber(ARGV[10]), tonumber(ARGV[11]), tonumber(ARGV[12]), tonumber(ARGV[11]), tonumber(ARGV[12])}
        for i = 1, 6 do
            redis.call('INCRBY', KEYS[i], amounts[i])
            redis.call('EXPIRE', KEYS[i], ttls[i])
        end
        local request_remaining = limits[1] > 0 and math.max(0, limits[1] - tonumber(redis.call('GET', KEYS[1]))) or -1
        local token_remaining = limits[2] > 0 and math.max(0, limits[2] - tonumber(redis.call('GET', KEYS[2]))) or -1
        return {1, 0, tonumber(ARGV[13]), request_remaining, token_remaining}
        LUA;

    private const RECONCILE_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[6]) ~= 'reserved' then
            return 0
        end
        local token_delta = tonumber(ARGV[1]) - tonumber(ARGV[2])
        local cost_delta = tonumber(ARGV[3]) - tonumber(ARGV[4])
        for i = 1, 3 do
            local value = tonumber(redis.call('INCRBY', KEYS[i], token_delta))
            if value < 0 then redis.call('SET', KEYS[i], 0) end
        end
        for i = 4, 5 do
            local value = tonumber(redis.call('INCRBY', KEYS[i], cost_delta))
            if value < 0 then redis.call('SET', KEYS[i], 0) end
        end
        for i = 1, 5 do
            if redis.call('TTL', KEYS[i]) < 0 then redis.call('EXPIRE', KEYS[i], tonumber(ARGV[5])) end
        end
        redis.call('SET', KEYS[6], 'reconciled', 'KEEPTTL')
        return 1
        LUA;

    private const ADJUST_SCRIPT = <<<'LUA'
        if redis.call('EXISTS', KEYS[5]) == 1 then return 1 end
        local token_delta = tonumber(ARGV[1])
        local cost_delta = tonumber(ARGV[2])
        for i = 1, 2 do
            local current = tonumber(redis.call('GET', KEYS[i]) or '0')
            if current + token_delta < 0 then return 0 end
        end
        for i = 3, 4 do
            local current = tonumber(redis.call('GET', KEYS[i]) or '0')
            if current + cost_delta < 0 then return 0 end
        end
        for i = 1, 2 do redis.call('INCRBY', KEYS[i], token_delta) end
        for i = 3, 4 do redis.call('INCRBY', KEYS[i], cost_delta) end
        redis.call('EXPIRE', KEYS[1], tonumber(ARGV[3]))
        redis.call('EXPIRE', KEYS[2], tonumber(ARGV[4]))
        redis.call('EXPIRE', KEYS[3], tonumber(ARGV[3]))
        redis.call('EXPIRE', KEYS[4], tonumber(ARGV[4]))
        redis.call('SET', KEYS[5], 'adjusted', 'EX', tonumber(ARGV[4]))
        return 1
        LUA;

    public function reserve(array $reservation): QuotaStoreResult
    {
        $keys = $this->keys($reservation);
        $arguments = [
            $reservation['request_limit'],
            $reservation['token_rate_limit'],
            $reservation['daily_token_limit'],
            $reservation['monthly_token_limit'],
            $reservation['daily_cost_limit'],
            $reservation['monthly_cost_limit'],
            $reservation['reserved_tokens'],
            $reservation['reserved_tokens'],
            $reservation['reserved_cost'],
            $reservation['minute_ttl'],
            $reservation['day_ttl'],
            $reservation['month_ttl'],
            $reservation['retry_after'],
            $reservation['day_retry_after'],
            $reservation['month_retry_after'],
        ];
        $result = $this->evaluate(self::RESERVE_SCRIPT, $keys, $arguments);
        $codes = [
            1 => 'request_rate_limit',
            2 => 'token_rate_limit',
            3 => 'token_budget_exceeded',
            4 => 'token_budget_exceeded',
            5 => 'cost_budget_exceeded',
            6 => 'cost_budget_exceeded',
            7 => 'duplicate_reservation',
        ];

        return new QuotaStoreResult(
            allowed: (int) $result[0] === 1,
            limitCode: $codes[(int) $result[1]] ?? null,
            retryAfter: max(1, (int) $result[2]),
            requestRemaining: (int) $result[3],
            tokenRemaining: (int) $result[4],
        );
    }

    public function reconcile(array $reconciliation): bool
    {
        $keys = $this->keys($reconciliation);
        $result = $this->evaluate(self::RECONCILE_SCRIPT, [
            $keys[1],
            $keys[2],
            $keys[3],
            $keys[4],
            $keys[5],
            $keys[6],
        ], [
            $reconciliation['actual_tokens'],
            $reconciliation['reserved_tokens'],
            $reconciliation['actual_cost'],
            $reconciliation['reserved_cost'],
            $reconciliation['cleanup_ttl'],
        ]);

        return (int) $result === 1;
    }

    public function adjust(array $adjustment): void
    {
        $keys = $this->keys($adjustment);
        $result = $this->evaluate(self::ADJUST_SCRIPT, [
            $keys[2],
            $keys[3],
            $keys[4],
            $keys[5],
            $keys[6],
        ], [
            $adjustment['adjustment_tokens'],
            $adjustment['adjustment_cost'],
            $adjustment['day_ttl'],
            $adjustment['month_ttl'],
        ]);
        if ((int) $result !== 1) {
            throw new \DomainException('A quota adjustment cannot make usage negative.');
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<int|string>  $arguments
     */
    private function evaluate(string $script, array $keys, array $arguments): mixed
    {
        try {
            /** @var Connection $connection */
            $connection = Redis::connection('cache');

            return $connection->eval($script, count($keys), ...$keys, ...$arguments);
        } catch (ConnectionException|RedisException $exception) {
            throw new QuotaStoreUnavailable('Quota accounting is unavailable.', previous: $exception);
        }
    }

    /**
     * @param  array<string, int|string>  $values
     * @return list<string>
     */
    private function keys(array $values): array
    {
        $tag = '{'.$values['application_key'].'}';

        return [
            "quota:{$tag}:request:".$values['minute_window'],
            "quota:{$tag}:tokens:minute:".$values['minute_window'],
            "quota:{$tag}:tokens:day:".$values['day_window'],
            "quota:{$tag}:tokens:month:".$values['month_window'],
            "quota:{$tag}:cost:day:".$values['day_window'],
            "quota:{$tag}:cost:month:".$values['month_window'],
            "quota:{$tag}:reservation:".$values['reservation_id'],
        ];
    }
}
