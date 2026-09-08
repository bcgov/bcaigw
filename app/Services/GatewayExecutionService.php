<?php

namespace App\Services;

use App\Auth\MachinePrincipal;
use App\DTO\PreparedGatewayCall;
use App\Enums\GatewayOperation;
use App\Enums\RoutingFailure;
use App\Exceptions\GatewayException;
use App\Exceptions\ModelRoutingException;
use App\Http\Middleware\AssignGatewayRequestId;
use Illuminate\Http\Request;

final readonly class GatewayExecutionService
{
    public function __construct(
        private ModelRoutingResolver $routing,
        private CanonicalRequestFactory $canonical,
        private AdapterRegistry $adapters,
        private GatewayCallAttemptService $attempts,
        private QuotaBudgetService $quotas,
        private CallContentRetentionService $content,
        private TelemetryMetrics $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function prepare(
        Request $httpRequest,
        MachinePrincipal $principal,
        GatewayOperation $operation,
        array $data,
    ): PreparedGatewayCall {
        $receivedAt = (float) $httpRequest->server('REQUEST_TIME_FLOAT', microtime(true));
        $idempotencyKey = $httpRequest->header('Idempotency-Key');
        if ($idempotencyKey !== null
            && (strlen($idempotencyKey) > 255
                || preg_match('/^[\x21-\x7E]+$/', $idempotencyKey) !== 1)) {
            throw new GatewayException(
                'invalid_request_error',
                'invalid_idempotency_key',
                400,
                'Idempotency-Key must contain 1-255 visible ASCII characters.',
                'Idempotency-Key',
            );
        }
        if (($data['stream'] ?? false) && $idempotencyKey !== null) {
            throw new GatewayException(
                'invalid_request_error',
                'idempotency_not_supported',
                400,
                'Idempotency-Key is not supported for streaming requests.',
                'Idempotency-Key',
            );
        }

        try {
            $decision = $this->routing->resolve($principal, $data['model'], $operation->capability());
        } catch (ModelRoutingException $exception) {
            throw $this->routingError($exception);
        }
        $request = $this->canonical->make($operation, $data, $decision);
        if ($request->streaming() && ! in_array('streaming', $decision->capabilities, true)) {
            throw new GatewayException(
                'invalid_request_error',
                'streaming_not_supported',
                400,
                'The selected model grant does not permit streaming.',
                'stream',
            );
        }

        $retainContent = $this->content->shouldRetain($principal->application);
        $clientMetadata = ClientMetadataSanitizer::sanitize($data['metadata'] ?? null);

        $attempt = $this->attempts->start(
            $principal,
            $decision,
            $operation,
            $request->streaming(),
            (string) $httpRequest->attributes->get(AssignGatewayRequestId::ATTRIBUTE),
            $idempotencyKey,
            is_string($data['model'] ?? null) ? $data['model'] : '',
            $clientMetadata,
            $retainContent,
        );

        try {
            $reservation = $this->quotas->reserve(
                $principal,
                $decision,
                $request,
                $attempt,
            );
        } catch (GatewayException $exception) {
            $this->metrics->recordBudgetRejection($exception->errorCode, $decision->modelId);
            $this->attempts->failed($attempt, $exception->errorCode);
            throw $exception;
        }

        $retainedRequest = null;
        $retainedRequestTruncated = false;
        if ($retainContent) {
            [$retainedRequest, $retainedRequestTruncated] = $this->content->bound(
                json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $this->content->requestCeiling($principal->application),
            );
        }

        return new PreparedGatewayCall(
            decision: $decision,
            request: $request,
            adapter: $this->adapters->for($decision->provider->type),
            attempt: $attempt,
            quotaReservation: $reservation,
            requestReceivedAt: $receivedAt,
            dispatchReadyAt: microtime(true),
            requestedModel: is_string($data['model'] ?? null) ? $data['model'] : $decision->modelId,
            credentialPublicId: $principal->credential->exists
                ? (string) $principal->credential->public_id
                : null,
            clientMetadata: $clientMetadata,
            retainContent: $retainContent,
            retainedRequest: $retainedRequest,
            retainedRequestTruncated: $retainedRequestTruncated,
        );
    }

    private function routingError(ModelRoutingException $exception): GatewayException
    {
        return match ($exception->failure) {
            RoutingFailure::ModelNotGranted,
            RoutingFailure::AliasUnavailable => new GatewayException(
                'invalid_request_error',
                'model_not_found',
                404,
                'The requested model is not available.',
                'model',
            ),
            RoutingFailure::CapabilityNotGranted => new GatewayException(
                'permission_error',
                'capability_not_granted',
                403,
                'The requested model capability is not granted.',
                'model',
            ),
            default => new GatewayException(
                'server_error',
                'model_temporarily_unavailable',
                503,
                'The requested model is temporarily unavailable.',
                'model',
            ),
        };
    }
}
