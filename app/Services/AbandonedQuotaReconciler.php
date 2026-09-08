<?php

namespace App\Services;

use App\DTO\PricingDecision;
use App\DTO\QuotaReservationContext;
use App\Enums\QuotaLedgerEvent;
use App\Models\QuotaLedgerEntry;

final readonly class AbandonedQuotaReconciler
{
    public function __construct(private QuotaBudgetService $quotas) {}

    public function reconcile(int $limit = 100): int
    {
        $reconciled = 0;
        $cutoff = now('UTC')->subSeconds(config('quota.reservation_timeout_seconds'));
        $reservations = QuotaLedgerEntry::query()
            ->with('application')
            ->where('event_type', QuotaLedgerEvent::Reserved->value)
            ->where('created_at', '<', $cutoff)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('quota_ledger_entries as completed')
                    ->whereColumn('completed.reservation_id', 'quota_ledger_entries.reservation_id')
                    ->whereIn('completed.event_type', [
                        QuotaLedgerEvent::Reconciled->value,
                        QuotaLedgerEvent::Recovered->value,
                    ]);
            })
            ->limit($limit)
            ->get();
        foreach ($reservations as $reservation) {
            $context = new QuotaReservationContext($reservation, -1, -1, 0);
            $pricing = $reservation->modelPricingVersion()->firstOrFail();
            $decision = new PricingDecision(
                $pricing->public_id,
                $pricing->effective_at->toDateTimeImmutable(),
                $pricing->currency,
                $pricing->input_cost_per_million_tokens,
                $pricing->output_cost_per_million_tokens,
                $pricing->cached_input_cost_per_million_tokens,
            );
            $this->quotas->recover($context, $decision);
            $reconciled++;
        }

        return $reconciled;
    }
}
