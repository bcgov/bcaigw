<?php

namespace App\Services;

use App\Auth\MachinePrincipal;
use App\Contracts\CanonicalGatewayRequest;
use App\Contracts\QuotaCounterStore;
use App\DTO\PricingDecision;
use App\DTO\QuotaReservationContext;
use App\DTO\RoutingDecision;
use App\Enums\QuotaLedgerEvent;
use App\Exceptions\GatewayException;
use App\Exceptions\QuotaStoreUnavailable;
use App\Models\Application;
use App\Models\GatewayCallAttempt;
use App\Models\QuotaLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class QuotaBudgetService
{
    public function __construct(
        private QuotaCounterStore $store,
        private ExactCostCalculator $costs,
    ) {}

    public function reserve(
        MachinePrincipal $principal,
        RoutingDecision $decision,
        CanonicalGatewayRequest $request,
        GatewayCallAttempt $attempt,
    ): QuotaReservationContext {
        $application = Application::query()->findOrFail($principal->application->id);
        if (strtoupper($application->budget_currency) !== strtoupper($decision->pricing->currency)) {
            throw new GatewayException(
                'server_error',
                'budget_currency_mismatch',
                503,
                'The application budget currency does not match model pricing.',
            );
        }
        $now = CarbonImmutable::now('UTC');
        $windows = $this->windows($now);
        $reservationId = (string) Str::ulid();
        $input = max(1, strlen(json_encode($request, JSON_THROW_ON_ERROR)));
        $output = max(0, (int) ($request->maxOutputTokens ?? 0));
        $tokens = $input + $output;
        $cost = $this->costs->reservationMicrounits($decision->pricing, $input, $output);
        $limits = $this->limits($application);
        $values = [
            'application_key' => $application->public_id,
            'reservation_id' => $reservationId,
            ...$windows,
            ...$limits,
            'reserved_tokens' => $tokens,
            'reserved_cost' => $cost,
        ];
        $common = $this->common(
            $reservationId,
            $attempt,
            $application,
            $decision,
            $windows,
            $limits,
        );
        QuotaLedgerEntry::create([
            ...$common,
            'event_type' => QuotaLedgerEvent::ReservationRequested,
            'reserved_input_tokens' => $input,
            'reserved_output_tokens' => $output,
            'reserved_cost_microunits' => $cost,
        ]);

        try {
            $result = $this->store->reserve($values);
        } catch (QuotaStoreUnavailable $exception) {
            QuotaLedgerEntry::create([
                ...$common,
                'event_type' => QuotaLedgerEvent::AccountingFailed,
                'reason' => 'redis_unavailable',
                'reserved_input_tokens' => $input,
                'reserved_output_tokens' => $output,
                'reserved_cost_microunits' => $cost,
            ]);
            throw new GatewayException(
                'server_error',
                'quota_accounting_unavailable',
                503,
                'Quota accounting is temporarily unavailable.',
            );
        }

        if (! $result->allowed) {
            QuotaLedgerEntry::create([
                ...$common,
                'event_type' => QuotaLedgerEvent::Rejected,
                'reason' => $result->limitCode,
                'reserved_input_tokens' => $input,
                'reserved_output_tokens' => $output,
                'reserved_cost_microunits' => $cost,
            ]);
            throw $this->limitException(
                $result->limitCode ?? 'token_budget_exceeded',
                $result->retryAfter,
                $limits,
                $result->requestRemaining,
                $result->tokenRemaining,
                $now,
            );
        }

        $ledger = QuotaLedgerEntry::create([
            ...$common,
            'event_type' => QuotaLedgerEvent::Reserved,
            'reserved_input_tokens' => $input,
            'reserved_output_tokens' => $output,
            'reserved_cost_microunits' => $cost,
        ]);

        return new QuotaReservationContext(
            $ledger,
            $result->requestRemaining,
            $result->tokenRemaining,
            $now->startOfMinute()->addMinute()->timestamp,
        );
    }

    public function reconcile(
        QuotaReservationContext $context,
        RoutingDecision|PricingDecision $decision,
        int $inputTokens,
        int $outputTokens,
        int $cachedInputTokens = 0,
        bool $usageReported = true,
        bool $recovered = false,
    ): void {
        $reservation = $context->ledger;
        if (QuotaLedgerEntry::query()
            ->where('reservation_id', $reservation->reservation_id)
            ->whereIn('event_type', [
                QuotaLedgerEvent::Reconciled->value,
                QuotaLedgerEvent::Recovered->value,
            ])->exists()) {
            return;
        }
        if (! $usageReported) {
            $inputTokens = $reservation->reserved_input_tokens;
            $outputTokens = $reservation->reserved_output_tokens;
            $cachedInputTokens = 0;
        }
        $actualTokens = max(0, $inputTokens) + max(0, $outputTokens);
        $pricing = $decision instanceof RoutingDecision ? $decision->pricing : $decision;
        $actualCost = $usageReported
            ? $this->costs->actualMicrounits(
                $pricing,
                $inputTokens,
                $outputTokens,
                $cachedInputTokens,
            )
            : $reservation->reserved_cost_microunits;
        $values = [
            'application_key' => $reservation->application->public_id,
            'reservation_id' => $reservation->reservation_id,
            'minute_window' => $reservation->minute_window,
            'day_window' => $reservation->day_window,
            'month_window' => $reservation->month_window,
            'reserved_tokens' => $reservation->reserved_input_tokens + $reservation->reserved_output_tokens,
            'reserved_cost' => $reservation->reserved_cost_microunits,
            'actual_tokens' => $actualTokens,
            'actual_cost' => $actualCost,
            'cleanup_ttl' => 3600,
        ];
        try {
            $this->store->reconcile($values);
        } catch (QuotaStoreUnavailable $exception) {
            throw new GatewayException(
                'server_error',
                'quota_accounting_unavailable',
                503,
                'Quota accounting is temporarily unavailable.',
            );
        }
        QuotaLedgerEntry::create([
            ...$reservation->only([
                'reservation_id',
                'gateway_call_attempt_id',
                'application_id',
                'model_pricing_version_id',
                'operation',
                'minute_window',
                'day_window',
                'month_window',
                'application_configuration_version',
                'alias_configuration_version',
                'target_configuration_version',
                'grant_configuration_version',
                'budget_snapshot',
                'reserved_input_tokens',
                'reserved_output_tokens',
                'reserved_cost_microunits',
            ]),
            'event_type' => $recovered ? QuotaLedgerEvent::Recovered : QuotaLedgerEvent::Reconciled,
            'actual_input_tokens' => max(0, $inputTokens),
            'actual_output_tokens' => max(0, $outputTokens),
            'actual_cached_input_tokens' => min(max(0, $cachedInputTokens), max(0, $inputTokens)),
            'actual_cost_microunits' => $actualCost,
            'released_tokens' => max(0, $reservation->reserved_input_tokens + $reservation->reserved_output_tokens - $actualTokens),
            'released_cost_microunits' => max(0, $reservation->reserved_cost_microunits - $actualCost),
            'usage_missing' => ! $usageReported,
            'reason' => $recovered ? 'abandoned_reservation' : null,
        ]);
    }

    public function recover(
        QuotaReservationContext $context,
        PricingDecision $pricing,
    ): void {
        $this->reconcile(
            $context,
            $pricing,
            0,
            0,
            usageReported: false,
            recovered: true,
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function windows(CarbonImmutable $now): array
    {
        $minuteEnd = $now->startOfMinute()->addMinute();
        $dayEnd = $now->startOfDay()->addDay();
        $monthEnd = $now->startOfMonth()->addMonth();

        return [
            'minute_window' => $now->format('YmdHi'),
            'day_window' => $now->format('Ymd'),
            'month_window' => $now->format('Ym'),
            'minute_ttl' => (int) $now->diffInSeconds($minuteEnd) + 120,
            'day_ttl' => (int) $now->diffInSeconds($dayEnd) + 172800,
            'month_ttl' => (int) $now->diffInSeconds($monthEnd) + 3456000,
            'retry_after' => max(1, (int) $now->diffInSeconds($minuteEnd)),
            'day_retry_after' => max(1, (int) $now->diffInSeconds($dayEnd)),
            'month_retry_after' => max(1, (int) $now->diffInSeconds($monthEnd)),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function limits(Application $application): array
    {
        return [
            'request_limit' => (int) ($application->rate_limit_per_minute ?? 0),
            'token_rate_limit' => (int) ($application->token_rate_per_minute ?? 0),
            'daily_token_limit' => (int) ($application->token_budget_daily ?? 0),
            'monthly_token_limit' => (int) ($application->token_budget_monthly ?? 0),
            'daily_cost_limit' => $application->cost_budget_daily !== null
                ? $this->costs->dollarsToMicrounits($application->cost_budget_daily)
                : 0,
            'monthly_cost_limit' => $application->cost_budget_monthly !== null
                ? $this->costs->dollarsToMicrounits($application->cost_budget_monthly)
                : 0,
        ];
    }

    /**
     * @param  array<string, int|string>  $windows
     * @param  array<string, int>  $limits
     * @return array<string, mixed>
     */
    private function common(
        string $reservationId,
        GatewayCallAttempt $attempt,
        Application $application,
        RoutingDecision $decision,
        array $windows,
        array $limits,
    ): array {
        return [
            'reservation_id' => $reservationId,
            'gateway_call_attempt_id' => $attempt->id,
            'application_id' => $application->id,
            'model_pricing_version_id' => $attempt->model_pricing_version_id,
            'operation' => $attempt->operation->value,
            'minute_window' => $windows['minute_window'],
            'day_window' => $windows['day_window'],
            'month_window' => $windows['month_window'],
            'application_configuration_version' => $application->status_version,
            'alias_configuration_version' => $decision->aliasConfigurationVersion,
            'target_configuration_version' => $decision->targetConfigurationVersion,
            'grant_configuration_version' => $decision->grantConfigurationVersion,
            'budget_snapshot' => [
                ...$limits,
                'currency' => strtoupper($application->budget_currency),
            ],
        ];
    }

    /**
     * @param  array<string, int>  $limits
     */
    private function limitException(
        string $code,
        int $retryAfter,
        array $limits,
        int $requestRemaining,
        int $tokenRemaining,
        CarbonImmutable $now,
    ): GatewayException {
        $headers = [
            'Retry-After' => (string) max(1, $retryAfter),
            'x-ratelimit-reset' => (string) ($now->timestamp + max(1, $retryAfter)),
        ];
        if ($limits['request_limit'] > 0) {
            $headers['x-ratelimit-limit-requests'] = (string) $limits['request_limit'];
            $headers['x-ratelimit-remaining-requests'] = (string) max(0, $requestRemaining);
        }
        if ($limits['token_rate_limit'] > 0) {
            $headers['x-ratelimit-limit-tokens'] = (string) $limits['token_rate_limit'];
            $headers['x-ratelimit-remaining-tokens'] = (string) max(0, $tokenRemaining);
        }

        return new GatewayException(
            'rate_limit_error',
            $code,
            429,
            match ($code) {
                'request_rate_limit' => 'The application request rate limit is exhausted.',
                'token_rate_limit' => 'The application token rate limit is exhausted.',
                'cost_budget_exceeded' => 'The application cost budget is exhausted.',
                default => 'The application token budget is exhausted.',
            },
            headers: $headers,
        );
    }
}
