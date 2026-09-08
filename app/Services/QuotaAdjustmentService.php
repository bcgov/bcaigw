<?php

namespace App\Services;

use App\Contracts\QuotaCounterStore;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Enums\QuotaLedgerEvent;
use App\Exceptions\QuotaStoreUnavailable;
use App\Models\Application;
use App\Models\QuotaLedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class QuotaAdjustmentService
{
    public function __construct(
        private QuotaCounterStore $store,
        private ExactCostCalculator $costs,
        private SecurityAuditRecorder $audit,
    ) {}

    public function adjust(
        Application $application,
        int $tokens,
        string $cost,
        string $reason,
        User $actor,
        Request $request,
    ): void {
        $now = CarbonImmutable::now('UTC');
        $costMicrounits = $this->costs->dollarsToMicrounits($cost);
        $values = [
            'application_key' => $application->public_id,
            'reservation_id' => (string) Str::ulid(),
            'minute_window' => $now->format('YmdHi'),
            'day_window' => $now->format('Ymd'),
            'month_window' => $now->format('Ym'),
            'adjustment_tokens' => $tokens,
            'adjustment_cost' => $costMicrounits,
            'day_ttl' => (int) $now->diffInSeconds($now->startOfDay()->addDays(3)),
            'month_ttl' => (int) $now->diffInSeconds($now->startOfMonth()->addMonths(2)),
        ];
        $ledger = [
            'reservation_id' => $values['reservation_id'],
            'application_id' => $application->id,
            'actor_user_id' => $actor->id,
            'reason' => $reason,
            'minute_window' => $values['minute_window'],
            'day_window' => $values['day_window'],
            'month_window' => $values['month_window'],
            'application_configuration_version' => $application->status_version,
            'budget_snapshot' => ['currency' => $application->budget_currency],
            'adjustment_tokens' => $tokens,
            'adjustment_cost_microunits' => $costMicrounits,
        ];
        QuotaLedgerEntry::create([
            ...$ledger,
            'event_type' => QuotaLedgerEvent::AdjustmentRequested,
        ]);
        try {
            $this->store->adjust($values);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['token_adjustment' => $exception->getMessage()]);
        } catch (QuotaStoreUnavailable) {
            throw ValidationException::withMessages([
                'token_adjustment' => 'Quota accounting is unavailable; no adjustment was applied.',
            ]);
        }
        QuotaLedgerEntry::create([
            ...$ledger,
            'event_type' => QuotaLedgerEvent::Adjustment,
        ]);
        $this->audit->record(
            $request,
            AuditEventType::QuotaBudgetAdjusted,
            AuditOutcome::Succeeded,
            actor: $actor,
            context: [
                'application_public_id' => $application->public_id,
                'quota_units_adjusted' => $tokens,
                'cost_adjustment_microunits' => $costMicrounits,
                'reason_length' => mb_strlen($reason),
            ],
        );
    }
}
