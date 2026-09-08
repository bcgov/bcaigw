<?php

namespace App\Http\Controllers;

use App\Auth\MachinePrincipal;
use App\DTO\CallOutcome;
use App\DTO\PreparedGatewayCall;
use App\Enums\CallAttemptStatus;
use App\Enums\ControlPlaneStatus;
use App\Enums\GatewayOperation;
use App\Exceptions\GatewayException;
use App\Http\Requests\ChatCompletionRequest;
use App\Http\Requests\EmbeddingRequest;
use App\Http\Requests\ResponseRequest;
use App\Models\ApplicationModelGrant;
use App\Services\ErrorCategory;
use App\Services\GatewayExecutionService;
use App\Services\QuotaBudgetService;
use App\Services\StreamContentAccumulator;
use App\Services\TelemetryRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class OpenAiGatewayController extends Controller
{
    #[OA\Get(
        path: '/v1/models',
        summary: 'List models granted to the authenticated application',
        security: [['machineBearer' => ['gateway.invoke']]],
        tags: ['OpenAI-compatible gateway'],
        responses: [
            new OA\Response(response: 200, description: 'Granted models'),
            new OA\Response(response: 401, description: 'Invalid bearer token', content: new OA\JsonContent(ref: '#/components/schemas/OpenAiError')),
            new OA\Response(response: 403, description: 'Insufficient scope', content: new OA\JsonContent(ref: '#/components/schemas/OpenAiError')),
        ],
    )]
    public function models(Request $request): JsonResponse
    {
        $principal = MachinePrincipal::fromRequest($request);
        $models = ApplicationModelGrant::query()
            ->with('alias')
            ->where('application_id', $principal->application->id)
            ->where('enabled', true)
            ->whereHas('alias', fn ($query) => $query
                ->where('status', ControlPlaneStatus::Active)
                ->whereHas('activeTarget', fn ($target) => $target
                    ->where('status', ControlPlaneStatus::Active)
                    ->whereHas('provider', fn ($provider) => $provider
                        ->where('status', ControlPlaneStatus::Active))))
            ->get()
            ->map(fn (ApplicationModelGrant $grant) => [
                'id' => $grant->alias->model_id,
                'object' => 'model',
                'created' => $grant->alias->created_at->timestamp,
                'owned_by' => 'bcgov',
                'capabilities' => $grant->capabilities,
            ])
            ->values();

        return response()->json(['object' => 'list', 'data' => $models]);
    }

    #[OA\Post(
        path: '/v1/chat/completions',
        summary: 'Create an OpenAI-compatible chat completion',
        security: [['machineBearer' => ['gateway.invoke']]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChatCompletionRequest')),
        tags: ['OpenAI-compatible gateway'],
        responses: [
            new OA\Response(response: 200, description: 'Completion or SSE stream'),
            new OA\Response(response: 422, description: 'Invalid request', content: new OA\JsonContent(ref: '#/components/schemas/OpenAiError')),
            new OA\Response(response: 429, description: 'Rate or concurrency limit', content: new OA\JsonContent(ref: '#/components/schemas/OpenAiError')),
        ],
    )]
    public function chat(
        ChatCompletionRequest $request,
        GatewayExecutionService $gateway,
        TelemetryRecorder $telemetry,
        QuotaBudgetService $quotas,
    ): JsonResponse|StreamedResponse {
        return $this->execute(
            $request,
            GatewayOperation::ChatCompletions,
            $request->validated(),
            $gateway,
            $telemetry,
            $quotas,
        );
    }

    #[OA\Post(
        path: '/v1/responses',
        summary: 'Create an OpenAI-compatible response',
        security: [['machineBearer' => ['gateway.invoke']]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ResponseRequest')),
        tags: ['OpenAI-compatible gateway'],
        responses: [
            new OA\Response(response: 200, description: 'Response or SSE stream'),
            new OA\Response(response: 422, description: 'Invalid request', content: new OA\JsonContent(ref: '#/components/schemas/OpenAiError')),
            new OA\Response(response: 429, description: 'Rate or concurrency limit', content: new OA\JsonContent(ref: '#/components/schemas/OpenAiError')),
        ],
    )]
    public function responses(
        ResponseRequest $request,
        GatewayExecutionService $gateway,
        TelemetryRecorder $telemetry,
        QuotaBudgetService $quotas,
    ): JsonResponse|StreamedResponse {
        return $this->execute(
            $request,
            GatewayOperation::Responses,
            $request->validated(),
            $gateway,
            $telemetry,
            $quotas,
        );
    }

    #[OA\Post(
        path: '/v1/embeddings',
        summary: 'Create OpenAI-compatible embeddings',
        security: [['machineBearer' => ['gateway.invoke']]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/EmbeddingRequest')),
        tags: ['OpenAI-compatible gateway'],
        responses: [
            new OA\Response(response: 200, description: 'Embedding list'),
            new OA\Response(response: 422, description: 'Invalid request', content: new OA\JsonContent(ref: '#/components/schemas/OpenAiError')),
            new OA\Response(response: 429, description: 'Rate or concurrency limit', content: new OA\JsonContent(ref: '#/components/schemas/OpenAiError')),
        ],
    )]
    public function embeddings(
        EmbeddingRequest $request,
        GatewayExecutionService $gateway,
        TelemetryRecorder $telemetry,
        QuotaBudgetService $quotas,
    ): JsonResponse {
        return $this->execute(
            $request,
            GatewayOperation::Embeddings,
            $request->validated(),
            $gateway,
            $telemetry,
            $quotas,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function execute(
        Request $request,
        GatewayOperation $operation,
        array $data,
        GatewayExecutionService $gateway,
        TelemetryRecorder $telemetry,
        QuotaBudgetService $quotas,
    ): JsonResponse|StreamedResponse {
        $prepared = $gateway->prepare(
            $request,
            MachinePrincipal::fromRequest($request),
            $operation,
            $data,
        );
        if ($prepared->request->streaming()) {
            return $this->stream($prepared, $telemetry, $quotas, $request);
        }

        $dispatchedAt = microtime(true);
        try {
            $result = $prepared->adapter->invoke($prepared->decision, $prepared->request);
            $upstreamMs = $this->elapsedMs($dispatchedAt);
            $quotas->reconcile(
                $prepared->quotaReservation,
                $prepared->decision,
                $result->promptTokens,
                $result->completionTokens,
                $result->cachedInputTokens,
                $result->usageReported,
            );
            $telemetry->finalize(
                $prepared,
                new CallOutcome(
                    status: CallAttemptStatus::Succeeded,
                    httpStatus: 200,
                    promptTokens: $result->promptTokens,
                    completionTokens: $result->completionTokens,
                    cachedInputTokens: $result->cachedInputTokens,
                    upstreamCorrelationId: $result->upstreamCorrelationId,
                    usageReported: $result->usageReported,
                    upstreamLatencyMs: $upstreamMs,
                ),
                $prepared->retainContent
                    ? (json_encode($result->body, JSON_UNESCAPED_SLASHES) ?: null)
                    : null,
            );

            return response()
                ->json($result->body)
                ->withHeaders($this->quotaHeaders($prepared));
        } catch (GatewayException $exception) {
            $upstreamMs = $this->elapsedMs($dispatchedAt);
            try {
                $quotas->reconcile(
                    $prepared->quotaReservation,
                    $prepared->decision,
                    0,
                    0,
                    usageReported: false,
                );
            } catch (GatewayException $accountingException) {
                $this->recordFailure($telemetry, $prepared, $accountingException, $upstreamMs);
                throw $accountingException;
            }
            $this->recordFailure($telemetry, $prepared, $exception, $upstreamMs);
            throw $exception;
        } catch (Throwable $exception) {
            $upstreamMs = $this->elapsedMs($dispatchedAt);
            try {
                $quotas->reconcile(
                    $prepared->quotaReservation,
                    $prepared->decision,
                    0,
                    0,
                    usageReported: false,
                );
            } catch (GatewayException $accountingException) {
                $this->recordFailure($telemetry, $prepared, $accountingException, $upstreamMs);
                throw $accountingException;
            }
            $telemetry->finalize($prepared, new CallOutcome(
                status: CallAttemptStatus::Failed,
                httpStatus: 500,
                errorCode: 'internal_error',
                errorCategory: ErrorCategory::INTERNAL,
                upstreamLatencyMs: $upstreamMs,
            ));
            throw $exception;
        }
    }

    private function stream(
        PreparedGatewayCall $prepared,
        TelemetryRecorder $telemetry,
        QuotaBudgetService $quotas,
        Request $request,
    ): StreamedResponse {
        return response()->stream(function () use ($prepared, $telemetry, $quotas, $request): void {
            ignore_user_abort(true);
            $emitted = false;
            $promptTokens = 0;
            $completionTokens = 0;
            $cachedInputTokens = 0;
            $usageReported = false;
            $correlationId = null;
            $dispatchedAt = microtime(true);
            $timeToFirstToken = null;
            $accumulator = new StreamContentAccumulator(
                $prepared->retainContent,
                (int) config('telemetry.content.max_response_bytes'),
                (int) config('telemetry.content.max_stream_chunks'),
            );
            try {
                foreach ($prepared->adapter->stream($prepared->decision, $prepared->request) as $event) {
                    if (connection_aborted()) {
                        try {
                            $quotas->reconcile(
                                $prepared->quotaReservation,
                                $prepared->decision,
                                $promptTokens,
                                $completionTokens,
                                $cachedInputTokens,
                                $usageReported,
                            );
                        } catch (GatewayException $accountingException) {
                            $telemetry->finalize($prepared, new CallOutcome(
                                status: CallAttemptStatus::Cancelled,
                                httpStatus: 200,
                                errorCode: $accountingException->errorCode,
                                errorCategory: ErrorCategory::of($accountingException->errorCode),
                                partial: $emitted,
                                cancelled: true,
                                upstreamLatencyMs: $this->elapsedMs($dispatchedAt),
                                timeToFirstTokenMs: $timeToFirstToken,
                            ), stream: $accumulator);

                            return;
                        }
                        $telemetry->finalize($prepared, new CallOutcome(
                            status: CallAttemptStatus::Cancelled,
                            httpStatus: 200,
                            errorCode: 'client_cancelled',
                            errorCategory: ErrorCategory::CANCELLED,
                            promptTokens: $promptTokens,
                            completionTokens: $completionTokens,
                            cachedInputTokens: $cachedInputTokens,
                            upstreamCorrelationId: $correlationId,
                            partial: $emitted,
                            cancelled: true,
                            usageReported: $usageReported,
                            upstreamLatencyMs: $this->elapsedMs($dispatchedAt),
                            timeToFirstTokenMs: $timeToFirstToken,
                        ), stream: $accumulator);

                        return;
                    }
                    $usage = $event['usage'] ?? $event['response']['usage'] ?? [];
                    $promptTokens = max(
                        $promptTokens,
                        (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                    );
                    $completionTokens = max(
                        $completionTokens,
                        (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
                    );
                    $cachedInputTokens = max(
                        $cachedInputTokens,
                        (int) (
                            $usage['prompt_tokens_details']['cached_tokens']
                            ?? $usage['input_tokens_details']['cached_tokens']
                            ?? 0
                        ),
                    );
                    $usageReported = $usageReported || $usage !== [];
                    if ($correlationId === null && is_string($event['id'] ?? null)) {
                        $correlationId = $event['id'];
                    }
                    echo 'data: '.json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
                    $emitted = true;
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                    // Recorded only after the client flush so retention never delays delivery.
                    $timeToFirstToken ??= $this->elapsedMs($dispatchedAt);
                    $accumulator->append($event);
                }
                $quotas->reconcile(
                    $prepared->quotaReservation,
                    $prepared->decision,
                    $promptTokens,
                    $completionTokens,
                    $cachedInputTokens,
                    $usageReported,
                );
                $telemetry->finalize($prepared, new CallOutcome(
                    status: CallAttemptStatus::Succeeded,
                    httpStatus: 200,
                    promptTokens: $promptTokens,
                    completionTokens: $completionTokens,
                    cachedInputTokens: $cachedInputTokens,
                    upstreamCorrelationId: $correlationId,
                    usageReported: $usageReported,
                    upstreamLatencyMs: $this->elapsedMs($dispatchedAt),
                    timeToFirstTokenMs: $timeToFirstToken,
                ), stream: $accumulator);
                echo "data: [DONE]\n\n";
            } catch (GatewayException $exception) {
                try {
                    $quotas->reconcile(
                        $prepared->quotaReservation,
                        $prepared->decision,
                        $promptTokens,
                        $completionTokens,
                        $cachedInputTokens,
                        $usageReported,
                    );
                } catch (GatewayException $accountingException) {
                    $exception = $accountingException;
                }
                $telemetry->finalize($prepared, new CallOutcome(
                    status: $emitted ? CallAttemptStatus::Partial : CallAttemptStatus::Failed,
                    httpStatus: $emitted ? 200 : $exception->status,
                    errorCode: $exception->errorCode,
                    errorCategory: ErrorCategory::of($exception->errorCode),
                    promptTokens: $promptTokens,
                    completionTokens: $completionTokens,
                    cachedInputTokens: $cachedInputTokens,
                    upstreamCorrelationId: $correlationId,
                    partial: $emitted,
                    usageReported: $usageReported,
                    upstreamLatencyMs: $this->elapsedMs($dispatchedAt),
                    timeToFirstTokenMs: $timeToFirstToken,
                ), stream: $accumulator);
                $error = [
                    'error' => [
                        'message' => $exception->getMessage(),
                        'type' => $exception->errorType,
                        'param' => $exception->parameter,
                        'code' => $exception->errorCode,
                    ],
                    'request_id' => $request->attributes->get('bcaigw.request_id'),
                ];
                echo 'data: '.json_encode($error, JSON_THROW_ON_ERROR)."\n\n";
                echo "data: [DONE]\n\n";
            } catch (Throwable) {
                $errorCode = 'internal_error';
                try {
                    $quotas->reconcile(
                        $prepared->quotaReservation,
                        $prepared->decision,
                        $promptTokens,
                        $completionTokens,
                        $cachedInputTokens,
                        $usageReported,
                    );
                } catch (GatewayException $accountingException) {
                    $errorCode = $accountingException->errorCode;
                }
                $telemetry->finalize($prepared, new CallOutcome(
                    status: $emitted ? CallAttemptStatus::Partial : CallAttemptStatus::Failed,
                    httpStatus: $emitted ? 200 : 500,
                    errorCode: $errorCode,
                    errorCategory: ErrorCategory::of($errorCode),
                    promptTokens: $promptTokens,
                    completionTokens: $completionTokens,
                    cachedInputTokens: $cachedInputTokens,
                    upstreamCorrelationId: $correlationId,
                    partial: $emitted,
                    usageReported: $usageReported,
                    upstreamLatencyMs: $this->elapsedMs($dispatchedAt),
                    timeToFirstTokenMs: $timeToFirstToken,
                ), stream: $accumulator);
                echo 'data: '.json_encode([
                    'error' => [
                        'message' => 'The gateway could not complete the stream.',
                        'type' => 'server_error',
                        'param' => null,
                        'code' => 'internal_error',
                    ],
                    'request_id' => $request->attributes->get('bcaigw.request_id'),
                ], JSON_THROW_ON_ERROR)."\n\n";
                echo "data: [DONE]\n\n";
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            ...$this->quotaHeaders($prepared),
        ]);
    }

    private function recordFailure(
        TelemetryRecorder $telemetry,
        PreparedGatewayCall $prepared,
        GatewayException $exception,
        ?int $upstreamLatencyMs,
    ): void {
        $telemetry->finalize($prepared, new CallOutcome(
            status: CallAttemptStatus::Failed,
            httpStatus: $exception->status,
            errorCode: $exception->errorCode,
            errorCategory: ErrorCategory::of($exception->errorCode),
            upstreamLatencyMs: $upstreamLatencyMs,
        ));
    }

    private function elapsedMs(float $since): int
    {
        return (int) max(0, round((microtime(true) - $since) * 1000));
    }

    /**
     * @return array<string, string>
     */
    private function quotaHeaders(PreparedGatewayCall $prepared): array
    {
        $headers = [
            'x-ratelimit-reset' => (string) $prepared->quotaReservation->resetAt,
        ];
        if ($prepared->quotaReservation->requestRemaining >= 0) {
            $headers['x-ratelimit-remaining-requests'] = (string) $prepared->quotaReservation->requestRemaining;
        }
        if ($prepared->quotaReservation->tokenRemaining >= 0) {
            $headers['x-ratelimit-remaining-tokens'] = (string) $prepared->quotaReservation->tokenRemaining;
        }

        return $headers;
    }
}
