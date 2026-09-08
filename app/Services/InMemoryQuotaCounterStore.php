<?php

namespace App\Services;

use App\Contracts\QuotaCounterStore;
use App\DTO\QuotaStoreResult;

final class InMemoryQuotaCounterStore implements QuotaCounterStore
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, bool> */
    private array $reservations = [];

    public function reserve(array $reservation): QuotaStoreResult
    {
        $keys = $this->keys($reservation);
        if (isset($this->reservations[(string) $reservation['reservation_id']])) {
            return new QuotaStoreResult(false, 'duplicate_reservation', 1, -1, -1);
        }
        $amounts = [1, $reservation['reserved_tokens'], $reservation['reserved_tokens'], $reservation['reserved_tokens'], $reservation['reserved_cost'], $reservation['reserved_cost']];
        $limits = [$reservation['request_limit'], $reservation['token_rate_limit'], $reservation['daily_token_limit'], $reservation['monthly_token_limit'], $reservation['daily_cost_limit'], $reservation['monthly_cost_limit']];
        $codes = ['request_rate_limit', 'token_rate_limit', 'token_budget_exceeded', 'token_budget_exceeded', 'cost_budget_exceeded', 'cost_budget_exceeded'];
        foreach ($keys as $index => $key) {
            if ((int) $limits[$index] > 0
                && ($this->counters[$key] ?? 0) + (int) $amounts[$index] > (int) $limits[$index]) {
                $retry = match ($index) {
                    2, 4 => (int) $reservation['day_retry_after'],
                    3, 5 => (int) $reservation['month_retry_after'],
                    default => (int) $reservation['retry_after'],
                };

                return new QuotaStoreResult(false, $codes[$index], $retry, -1, -1);
            }
        }
        foreach ($keys as $index => $key) {
            $this->counters[$key] = ($this->counters[$key] ?? 0) + (int) $amounts[$index];
        }
        $this->reservations[(string) $reservation['reservation_id']] = true;

        return new QuotaStoreResult(
            true,
            null,
            (int) $reservation['retry_after'],
            (int) $reservation['request_limit'] > 0
                ? (int) $reservation['request_limit'] - $this->counters[$keys[0]]
                : -1,
            (int) $reservation['token_rate_limit'] > 0
                ? (int) $reservation['token_rate_limit'] - $this->counters[$keys[1]]
                : -1,
        );
    }

    public function reconcile(array $reconciliation): bool
    {
        $id = (string) $reconciliation['reservation_id'];
        if (($this->reservations[$id] ?? null) !== true) {
            return false;
        }
        $keys = $this->keys($reconciliation);
        $tokenDelta = (int) $reconciliation['actual_tokens'] - (int) $reconciliation['reserved_tokens'];
        $costDelta = (int) $reconciliation['actual_cost'] - (int) $reconciliation['reserved_cost'];
        foreach ([1, 2, 3] as $index) {
            $this->counters[$keys[$index]] = max(0, ($this->counters[$keys[$index]] ?? 0) + $tokenDelta);
        }
        foreach ([4, 5] as $index) {
            $this->counters[$keys[$index]] = max(0, ($this->counters[$keys[$index]] ?? 0) + $costDelta);
        }
        $this->reservations[$id] = false;

        return true;
    }

    public function adjust(array $adjustment): void
    {
        $keys = $this->keys($adjustment);
        foreach ([2, 3] as $index) {
            $next = ($this->counters[$keys[$index]] ?? 0) + (int) $adjustment['adjustment_tokens'];
            if ($next < 0) {
                throw new \DomainException('A quota adjustment cannot make usage negative.');
            }
            $this->counters[$keys[$index]] = $next;
        }
        foreach ([4, 5] as $index) {
            $next = ($this->counters[$keys[$index]] ?? 0) + (int) $adjustment['adjustment_cost'];
            if ($next < 0) {
                throw new \DomainException('A quota adjustment cannot make usage negative.');
            }
            $this->counters[$keys[$index]] = $next;
        }
    }

    /**
     * @param  array<string, int|string>  $values
     * @return list<string>
     */
    private function keys(array $values): array
    {
        $prefix = (string) $values['application_key'];

        return [
            "{$prefix}:request:".$values['minute_window'],
            "{$prefix}:token-minute:".$values['minute_window'],
            "{$prefix}:token-day:".$values['day_window'],
            "{$prefix}:token-month:".$values['month_window'],
            "{$prefix}:cost-day:".$values['day_window'],
            "{$prefix}:cost-month:".$values['month_window'],
        ];
    }
}
