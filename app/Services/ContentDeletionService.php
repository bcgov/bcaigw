<?php

namespace App\Services;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Enums\ContentState;
use App\Enums\TelemetryDeletionMode;
use App\Enums\TelemetryDeletionScope;
use App\Models\Application;
use App\Models\GatewayCallAttempt;
use App\Models\GatewayCallContent;
use App\Models\TelemetryContentDeletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual, audited destruction of retained content and detailed telemetry.
 *
 * Content retention is indefinite by policy, so deletion is always an explicit
 * administrator action with a recorded reason. Two guarantees shape the design:
 *
 *  - Destroying the `gateway_call_contents` row destroys the wrapped data key.
 *    Because content is sealed with a per-record key, this crypto-shreds the
 *    payload, so any ciphertext that survives in a database backup is
 *    unrecoverable once the backup containing the key is aged out.
 *  - Accounting integrity is preserved. Attempts are referenced by the
 *    append-only quota ledger, so detail deletion redacts the row in place to a
 *    minimal tombstone (identifiers, timing bucket, tokens and cost stay) rather
 *    than removing it, and the deletion batch itself is an append-only record.
 */
final readonly class ContentDeletionService
{
    /** Detail columns cleared when an administrator deletes detailed records. */
    private const REDACTED_COLUMNS = [
        'requested_model' => null,
        'client_metadata' => null,
        'upstream_correlation_id' => null,
        'error_code' => null,
        'queue_latency_ms' => null,
        'upstream_latency_ms' => null,
        'total_latency_ms' => null,
        'time_to_first_token_ms' => null,
        'first_byte_at' => null,
        'idempotency_key_hash' => null,
        'machine_credential_id' => null,
    ];

    public function __construct(private SecurityAuditRecorder $audit) {}

    /**
     * @param  list<string>  $requestIds
     */
    public function delete(
        User $administrator,
        Request $request,
        TelemetryDeletionScope $scope,
        TelemetryDeletionMode $mode,
        string $reason,
        ?Application $application = null,
        array $requestIds = [],
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): TelemetryContentDeletion {
        $query = $this->scopeQuery($scope, $application, $requestIds, $from, $to);

        return DB::transaction(function () use (
            $query,
            $scope,
            $mode,
            $reason,
            $application,
            $requestIds,
            $from,
            $to,
            $administrator,
            $request,
        ): TelemetryContentDeletion {
            /** @var list<int> $attemptIds */
            $attemptIds = $query->lockForUpdate()->pluck('gateway_call_attempts.id')->all();

            // The tombstone record is append-only, so the counters are measured
            // before it is written rather than patched afterwards. Both the
            // measurement and the destruction happen in one transaction, so the
            // recorded totals cannot drift from the work performed.
            $destroyed = GatewayCallContent::query()
                ->whereIn('gateway_call_attempt_id', $attemptIds)
                ->count();
            $redacted = $mode === TelemetryDeletionMode::ContentAndDetails ? count($attemptIds) : 0;

            $deletion = TelemetryContentDeletion::create([
                'scope' => $scope,
                'mode' => $mode,
                'reason' => mb_substr($reason, 0, 1000),
                'actor_user_id' => $administrator->getKey(),
                'application_id' => $application?->getKey(),
                'range_from' => $from,
                'range_to' => $to,
                'matched_attempts' => count($attemptIds),
                'content_records_destroyed' => $destroyed,
                'details_redacted' => $redacted,
                'request_ids' => $scope === TelemetryDeletionScope::Selected
                    ? array_slice($requestIds, 0, 100)
                    : null,
            ]);

            foreach (array_chunk($attemptIds, 500) as $chunk) {
                GatewayCallContent::query()
                    ->whereIn('gateway_call_attempt_id', $chunk)
                    ->delete();

                $tombstone = [
                    'telemetry_content_deletion_id' => $deletion->id,
                    'content_state' => ContentState::Deleted->value,
                    'content_deleted_at' => now(),
                ];
                if ($mode === TelemetryDeletionMode::ContentAndDetails) {
                    $tombstone = [
                        ...$tombstone,
                        ...self::REDACTED_COLUMNS,
                        'details_redacted_at' => now(),
                    ];
                }
                GatewayCallAttempt::query()->whereIn('id', $chunk)->update($tombstone);
            }

            $this->audit->record(
                $request,
                AuditEventType::TelemetryContentDeleted,
                AuditOutcome::Succeeded,
                actor: $administrator,
                context: [
                    'deletion_public_id' => $deletion->public_id,
                    'scope' => $scope->value,
                    'mode' => $mode->value,
                    'application_public_id' => $application?->public_id,
                    'matched_attempts' => count($attemptIds),
                    'content_records_destroyed' => $destroyed,
                    'details_redacted' => $redacted,
                    'reason' => mb_substr($reason, 0, 200),
                ],
            );

            return $deletion;
        });
    }

    /**
     * @param  list<string>  $requestIds
     * @return Builder<GatewayCallAttempt>
     */
    private function scopeQuery(
        TelemetryDeletionScope $scope,
        ?Application $application,
        array $requestIds,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
    ): Builder {
        $query = GatewayCallAttempt::query();

        match ($scope) {
            TelemetryDeletionScope::Selected => $this->applySelected($query, $requestIds),
            TelemetryDeletionScope::Application => $this->applyApplication($query, $application),
            TelemetryDeletionScope::Range => $this->applyRange($query, $application, $from, $to),
        };

        return $query;
    }

    /**
     * @param  Builder<GatewayCallAttempt>  $query
     * @param  list<string>  $requestIds
     */
    private function applySelected(Builder $query, array $requestIds): void
    {
        $max = (int) config('telemetry.deletion.max_selected');
        if ($requestIds === [] || count($requestIds) > $max) {
            throw ValidationException::withMessages([
                'request_ids' => "Select between 1 and {$max} calls to delete.",
            ]);
        }
        $query->whereIn('gateway_call_attempts.request_id', $requestIds);
    }

    /**
     * @param  Builder<GatewayCallAttempt>  $query
     */
    private function applyApplication(Builder $query, ?Application $application): void
    {
        if ($application === null) {
            throw ValidationException::withMessages([
                'application' => 'An application is required for application-scoped deletion.',
            ]);
        }
        $query->where('gateway_call_attempts.application_id', $application->getKey());
    }

    /**
     * @param  Builder<GatewayCallAttempt>  $query
     */
    private function applyRange(
        Builder $query,
        ?Application $application,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
    ): void {
        if ($from === null || $to === null || $from > $to) {
            throw ValidationException::withMessages([
                'range_from' => 'A valid start and end timestamp are required for range deletion.',
            ]);
        }
        $maxDays = (int) config('telemetry.deletion.max_range_days');
        if ($from->diffInDays($to) > $maxDays) {
            throw ValidationException::withMessages([
                'range_to' => "Deletion ranges are limited to {$maxDays} days.",
            ]);
        }
        $query->whereBetween('gateway_call_attempts.started_at', [$from, $to]);
        if ($application !== null) {
            $query->where('gateway_call_attempts.application_id', $application->getKey());
        }
    }
}
