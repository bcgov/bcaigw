<?php

namespace App\Services;

use App\DTO\CallOutcome;
use App\DTO\PreparedGatewayCall;
use App\Enums\ContentState;
use App\Models\Application;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single place where a gateway call attempt is finalized.
 *
 * Writes safe indexed metadata to `gateway_call_attempts`, seals retained
 * content into the separate content table, emits a structured log line and
 * updates Prometheus-compatible metrics. Nothing written here may contain
 * prompt/response bytes, credentials, tokens or upstream endpoints.
 */
final readonly class TelemetryRecorder
{
    public function __construct(
        private GatewayCallAttemptService $attempts,
        private CallContentRetentionService $content,
        private TelemetryMetrics $metrics,
        private ExactCostCalculator $costs,
    ) {}

    public function finalize(
        PreparedGatewayCall $prepared,
        CallOutcome $outcome,
        ?string $responsePayload = null,
        ?StreamContentAccumulator $stream = null,
    ): void {
        $totalLatencyMs = (int) max(0, round((microtime(true) - $prepared->requestReceivedAt) * 1000));
        $costMicrounits = $this->cost($prepared, $outcome);

        $contentState = ContentState::NotRetained;
        if ($prepared->retainContent) {
            $contentState = $this->persistContent($prepared, $responsePayload, $stream);
        }

        try {
            $this->attempts->finalize(
                $prepared->attempt,
                $outcome,
                queueLatencyMs: $prepared->queueLatencyMs(),
                totalLatencyMs: $totalLatencyMs,
                costMicrounits: $costMicrounits,
                costCurrency: $prepared->decision->pricing->currency,
                contentState: $contentState,
            );
        } catch (Throwable $exception) {
            // Telemetry persistence must not mask the caller's real outcome.
            Log::channel($this->channel())->error('bcaigw.telemetry.persist_failed', [
                'request_id' => (string) $prepared->attempt->request_id,
                'reason' => $exception::class,
            ]);
        }

        $this->metrics->recordCall(
            $prepared->decision,
            $prepared->request->operation(),
            $outcome,
            $prepared->request->streaming(),
            $totalLatencyMs,
        );
        $this->metrics->recordCost($prepared->decision, $costMicrounits);

        Log::channel($this->channel())->info('bcaigw.gateway.call', [
            'request_id' => (string) $prepared->attempt->request_id,
            'application_public_id' => $prepared->decision->applicationPublicId,
            'credential_public_id' => $prepared->credentialPublicId,
            'operation' => $prepared->request->operation()->value,
            'requested_model' => $prepared->requestedModel,
            'model' => $prepared->decision->modelId,
            'target_public_id' => $prepared->decision->targetPublicId,
            'provider_public_id' => $prepared->decision->provider->publicId,
            'provider_type' => $prepared->decision->provider->type->value,
            'pricing_version_public_id' => $prepared->decision->pricing->versionPublicId,
            'alias_configuration_version' => $prepared->decision->aliasConfigurationVersion,
            'target_configuration_version' => $prepared->decision->targetConfigurationVersion,
            'grant_configuration_version' => $prepared->decision->grantConfigurationVersion,
            'streaming' => $prepared->request->streaming(),
            'status' => $outcome->status->value,
            'http_status' => $outcome->httpStatus,
            'error_category' => $outcome->errorCategory,
            'error_code' => $outcome->errorCode,
            'prompt_tokens' => $outcome->promptTokens,
            'completion_tokens' => $outcome->completionTokens,
            'cached_input_tokens' => $outcome->cachedInputTokens,
            'total_tokens' => $outcome->totalTokens(),
            'cost_microunits' => $costMicrounits,
            'currency' => $prepared->decision->pricing->currency,
            'queue_latency_ms' => $prepared->queueLatencyMs(),
            'upstream_latency_ms' => $outcome->upstreamLatencyMs,
            'total_latency_ms' => $totalLatencyMs,
            'time_to_first_token_ms' => $outcome->timeToFirstTokenMs,
            'retry_count' => $outcome->retryCount,
            'client_cancelled' => $outcome->cancelled,
            'partial' => $outcome->partial,
            'upstream_correlation_id' => $outcome->upstreamCorrelationId,
            'content_state' => $contentState->value,
        ]);
    }

    private function persistContent(
        PreparedGatewayCall $prepared,
        ?string $responsePayload,
        ?StreamContentAccumulator $stream,
    ): ContentState {
        $application = Application::query()->find($prepared->attempt->application_id);
        if ($application === null) {
            return ContentState::Unavailable;
        }

        $response = $stream?->transcript() ?? $responsePayload;
        $responseTruncated = $stream?->truncated() ?? false;
        if ($response !== null && $stream === null) {
            [$response, $responseTruncated] = $this->content->bound(
                $response,
                $this->content->responseCeiling($application),
            );
        }

        return $this->content->store(
            $prepared->attempt,
            $application,
            $prepared->retainedRequest,
            $prepared->retainedRequestTruncated,
            $response,
            $responseTruncated,
            $stream?->observedChunks() ?? 0,
        );
    }

    private function cost(PreparedGatewayCall $prepared, CallOutcome $outcome): int
    {
        if ($outcome->totalTokens() === 0) {
            return 0;
        }

        return $this->costs->actualMicrounits(
            $prepared->decision->pricing,
            max(0, $outcome->promptTokens),
            max(0, $outcome->completionTokens),
            max(0, $outcome->cachedInputTokens),
        );
    }

    private function channel(): string
    {
        return (string) config('logging.telemetry_channel', 'stack');
    }
}
