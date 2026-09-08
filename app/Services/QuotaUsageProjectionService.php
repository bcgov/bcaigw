<?php

namespace App\Services;

use App\Enums\QuotaLedgerEvent;
use App\Models\Application;
use App\Models\QuotaLedgerEntry;
use Carbon\CarbonImmutable;

final readonly class QuotaUsageProjectionService
{
    public function forApplication(Application $application): array
    {
        $now = CarbonImmutable::now('UTC');
        $events = [
            QuotaLedgerEvent::Reconciled->value,
            QuotaLedgerEvent::Recovered->value,
            QuotaLedgerEvent::Adjustment->value,
        ];
        $day = QuotaLedgerEntry::query()
            ->where('application_id', $application->id)
            ->where('day_window', $now->format('Ymd'))
            ->whereIn('event_type', $events);
        $month = QuotaLedgerEntry::query()
            ->where('application_id', $application->id)
            ->where('month_window', $now->format('Ym'))
            ->whereIn('event_type', $events);
        $minuteRequests = QuotaLedgerEntry::query()
            ->where('application_id', $application->id)
            ->where('minute_window', $now->format('YmdHi'))
            ->where('event_type', QuotaLedgerEvent::Reserved->value)
            ->count();
        $outstanding = QuotaLedgerEntry::query()
            ->where('application_id', $application->id)
            ->where('event_type', QuotaLedgerEvent::Reserved->value)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('quota_ledger_entries as completed')
                    ->whereColumn('completed.reservation_id', 'quota_ledger_entries.reservation_id')
                    ->whereIn('completed.event_type', [
                        QuotaLedgerEvent::Reconciled->value,
                        QuotaLedgerEvent::Recovered->value,
                    ]);
            });
        $dailyOutstanding = (clone $outstanding)->where('day_window', $now->format('Ymd'));
        $monthlyOutstanding = (clone $outstanding)->where('month_window', $now->format('Ym'));
        $dailyTokens = (int) ((clone $day)->sum('actual_input_tokens')
            + (clone $day)->sum('actual_output_tokens')
            + (clone $day)->sum('adjustment_tokens'));
        $monthlyTokens = (int) ((clone $month)->sum('actual_input_tokens')
            + (clone $month)->sum('actual_output_tokens')
            + (clone $month)->sum('adjustment_tokens'));
        $dailyCost = (int) ((clone $day)->sum('actual_cost_microunits')
            + (clone $day)->sum('adjustment_cost_microunits'));
        $monthlyCost = (int) ((clone $month)->sum('actual_cost_microunits')
            + (clone $month)->sum('adjustment_cost_microunits'));
        $dailyOutstandingTokens = (int) ((clone $dailyOutstanding)->sum('reserved_input_tokens')
            + (clone $dailyOutstanding)->sum('reserved_output_tokens'));
        $monthlyOutstandingTokens = (int) ((clone $monthlyOutstanding)->sum('reserved_input_tokens')
            + (clone $monthlyOutstanding)->sum('reserved_output_tokens'));
        $dailyOutstandingCost = (int) (clone $dailyOutstanding)->sum('reserved_cost_microunits');
        $monthlyOutstandingCost = (int) (clone $monthlyOutstanding)->sum('reserved_cost_microunits');

        return [
            'timezone' => 'UTC',
            'minute' => [
                'requests' => $minuteRequests,
                'request_limit' => $application->rate_limit_per_minute,
                'token_limit' => $application->token_rate_per_minute,
            ],
            'daily' => [
                'tokens' => $dailyTokens,
                'outstanding_tokens' => $dailyOutstandingTokens,
                'projected_tokens' => $dailyTokens + $dailyOutstandingTokens,
                'token_limit' => $application->token_budget_daily,
                'cost' => $this->cost($dailyCost),
                'outstanding_cost' => $this->cost($dailyOutstandingCost),
                'projected_cost' => $this->cost($dailyCost + $dailyOutstandingCost),
                'cost_limit' => $application->cost_budget_daily,
            ],
            'monthly' => [
                'tokens' => $monthlyTokens,
                'outstanding_tokens' => $monthlyOutstandingTokens,
                'projected_tokens' => $monthlyTokens + $monthlyOutstandingTokens,
                'token_limit' => $application->token_budget_monthly,
                'cost' => $this->cost($monthlyCost),
                'outstanding_cost' => $this->cost($monthlyOutstandingCost),
                'projected_cost' => $this->cost($monthlyCost + $monthlyOutstandingCost),
                'cost_limit' => $application->cost_budget_monthly,
            ],
            'currency' => $application->budget_currency,
        ];
    }

    private function cost(int $microunits): string
    {
        return bcdiv((string) $microunits, '1000000', 6);
    }
}
