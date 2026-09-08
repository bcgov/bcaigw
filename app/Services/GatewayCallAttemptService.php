<?php

namespace App\Services;

use App\Auth\MachinePrincipal;
use App\DTO\CallOutcome;
use App\DTO\RoutingDecision;
use App\Enums\CallAttemptStatus;
use App\Enums\ContentState;
use App\Enums\GatewayOperation;
use App\Exceptions\GatewayException;
use App\Models\GatewayCallAttempt;
use App\Models\ModelPricingVersion;
use App\Models\PublicModelAlias;
use App\Models\UpstreamTarget;
use Illuminate\Database\QueryException;

final class GatewayCallAttemptService
{
    /**
     * @param  array<string, string|int|bool>  $clientMetadata
     */
    public function start(
        MachinePrincipal $principal,
        RoutingDecision $decision,
        GatewayOperation $operation,
        bool $streaming,
        string $requestId,
        ?string $idempotencyKey,
        string $requestedModel = '',
        array $clientMetadata = [],
        bool $retainContent = false,
    ): GatewayCallAttempt {
        $idempotencyHash = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey)
            : null;
        if ($idempotencyHash !== null
            && GatewayCallAttempt::query()
                ->where('application_id', $principal->application->id)
                ->where('operation', $operation)
                ->where('idempotency_key_hash', $idempotencyHash)
                ->exists()) {
            throw new GatewayException(
                'conflict_error',
                'idempotency_conflict',
                409,
                'This idempotency key has already been used for the operation.',
                'Idempotency-Key',
            );
        }

        $alias = PublicModelAlias::query()->where('model_id', $decision->modelId)->firstOrFail();
        $target = UpstreamTarget::query()->where('public_id', $decision->targetPublicId)->firstOrFail();
        $pricing = ModelPricingVersion::query()
            ->where('public_id', $decision->pricing->versionPublicId)
            ->firstOrFail();

        try {
            return GatewayCallAttempt::create([
                'request_id' => $requestId,
                'application_id' => $principal->application->id,
                'machine_credential_id' => $principal->credential->exists
                    ? $principal->credential->id
                    : null,
                'public_model_alias_id' => $alias->id,
                'upstream_target_id' => $target->id,
                'provider_account_id' => $target->provider_account_id,
                'model_pricing_version_id' => $pricing->id,
                'operation' => $operation,
                'requested_model' => mb_substr(
                    $requestedModel !== '' ? $requestedModel : $decision->modelId,
                    0,
                    255,
                ),
                'resolved_model_alias' => mb_substr($decision->modelId, 0, 255),
                'streaming' => $streaming,
                'status' => CallAttemptStatus::Started,
                'idempotency_key_hash' => $idempotencyHash,
                'alias_configuration_version' => $decision->aliasConfigurationVersion,
                'target_configuration_version' => $decision->targetConfigurationVersion,
                'grant_configuration_version' => $decision->grantConfigurationVersion,
                'application_status_version' => $principal->application->status_version,
                'client_metadata' => $clientMetadata === [] ? null : $clientMetadata,
                'content_retention_enabled' => $retainContent,
                'content_state' => ContentState::NotRetained,
                'started_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($idempotencyHash === null
                || ! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            throw new GatewayException(
                'conflict_error',
                'idempotency_conflict',
                409,
                'This idempotency key has already been used for the operation.',
                'Idempotency-Key',
            );
        }
    }

    /**
     * Record the complete safe telemetry envelope for a finished attempt.
     */
    public function finalize(
        GatewayCallAttempt $attempt,
        CallOutcome $outcome,
        ?int $queueLatencyMs = null,
        ?int $totalLatencyMs = null,
        int $costMicrounits = 0,
        ?string $costCurrency = null,
        ContentState $contentState = ContentState::NotRetained,
    ): void {
        $attempt->forceFill([
            'status' => $outcome->status,
            'http_status' => $outcome->httpStatus > 0 ? $outcome->httpStatus : null,
            'error_code' => $outcome->errorCode,
            'error_category' => $outcome->errorCategory,
            'prompt_tokens' => max(0, $outcome->promptTokens),
            'completion_tokens' => max(0, $outcome->completionTokens),
            'cached_input_tokens' => max(0, $outcome->cachedInputTokens),
            'total_tokens' => $outcome->totalTokens(),
            'cost_microunits' => $costMicrounits,
            'cost_currency' => $costCurrency !== null ? strtoupper($costCurrency) : null,
            'upstream_correlation_id' => $outcome->upstreamCorrelationId !== null
                ? mb_substr($outcome->upstreamCorrelationId, 0, 255)
                : $attempt->upstream_correlation_id,
            'queue_latency_ms' => $queueLatencyMs,
            'upstream_latency_ms' => $outcome->upstreamLatencyMs,
            'total_latency_ms' => $totalLatencyMs,
            'time_to_first_token_ms' => $outcome->timeToFirstTokenMs,
            'first_byte_at' => $outcome->timeToFirstTokenMs !== null && $attempt->started_at !== null
                ? $attempt->started_at->addMilliseconds($outcome->timeToFirstTokenMs)
                : $attempt->first_byte_at,
            'retry_count' => $outcome->retryCount,
            'client_cancelled' => $outcome->cancelled,
            'partial_response' => $outcome->partial,
            'content_state' => $contentState,
            'completed_at' => now(),
        ])->save();
    }

    public function succeeded(
        GatewayCallAttempt $attempt,
        int $promptTokens,
        int $completionTokens,
        ?string $correlationId,
    ): void {
        $this->finalize($attempt, new CallOutcome(
            status: CallAttemptStatus::Succeeded,
            httpStatus: 200,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            upstreamCorrelationId: $correlationId,
            usageReported: true,
        ));
    }

    public function failed(
        GatewayCallAttempt $attempt,
        string $code,
        bool $partial = false,
        bool $cancelled = false,
        int $promptTokens = 0,
        int $completionTokens = 0,
    ): void {
        $this->finalize($attempt, new CallOutcome(
            status: $cancelled
                ? CallAttemptStatus::Cancelled
                : ($partial ? CallAttemptStatus::Partial : CallAttemptStatus::Failed),
            httpStatus: 0,
            errorCode: $code,
            errorCategory: ErrorCategory::of($code),
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            partial: $partial,
            cancelled: $cancelled,
        ));
    }
}
