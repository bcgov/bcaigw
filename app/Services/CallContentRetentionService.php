<?php

namespace App\Services;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Enums\ContentState;
use App\Exceptions\ContentEncryptionException;
use App\Models\Application;
use App\Models\GatewayCallAttempt;
use App\Models\GatewayCallContent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Application-controlled retention of prompt/input and normalized
 * response/output content.
 *
 * Retention is decided once, at request preparation time, from the owning
 * application's `prompt_response_retention_enabled` flag. Toggling retention
 * therefore only ever applies to calls made after the change: content is never
 * collected retroactively, and an already-captured payload is not resurrected
 * by re-enabling the flag.
 *
 * When retention is disabled the raw payloads are never handed to this service
 * at all, so no content byte can reach the database, logs, audit records,
 * queues, traces or exception reports.
 */
final readonly class CallContentRetentionService
{
    public function __construct(
        private EnvelopeContentCipher $cipher,
        private TelemetryMetrics $metrics,
        private SecurityAuditRecorder $audit,
    ) {}

    public function shouldRetain(Application $application): bool
    {
        return (bool) config('telemetry.content.capture_enabled')
            && (bool) $application->prompt_response_retention_enabled;
    }

    public function requestCeiling(Application $application): int
    {
        return $this->ceiling($application, (int) config('telemetry.content.max_request_bytes'));
    }

    public function responseCeiling(Application $application): int
    {
        return $this->ceiling($application, (int) config('telemetry.content.max_response_bytes'));
    }

    /**
     * Truncate to a byte ceiling and mark the payload so a reader can tell that
     * the stored bytes are not the complete content.
     *
     * @return array{0: string, 1: bool}
     */
    public function bound(string $payload, int $ceiling): array
    {
        if (strlen($payload) <= $ceiling) {
            return [$payload, false];
        }

        $marker = (string) config('telemetry.content.truncation_marker');
        $room = max(0, $ceiling - strlen($marker));

        return [substr($payload, 0, $room).$marker, true];
    }

    /**
     * Seal and persist the retained payloads. Failures degrade to
     * `ContentState::Unavailable` and never fail the caller's request.
     */
    public function store(
        GatewayCallAttempt $attempt,
        Application $application,
        ?string $requestPayload,
        bool $requestTruncated,
        ?string $responsePayload,
        bool $responseTruncated,
        int $streamChunks = 0,
    ): ContentState {
        if ($requestPayload === null && $responsePayload === null) {
            return ContentState::Unavailable;
        }
        if (! $this->cipher->isAvailable()) {
            $this->metrics->recordContentCaptureSkipped('no_encryption_key');
            Log::channel(config('logging.telemetry_channel', 'stack'))->warning(
                'bcaigw.content.capture_skipped',
                ['reason' => 'no_encryption_key', 'request_id' => $attempt->request_id],
            );

            return ContentState::Unavailable;
        }

        try {
            $sealed = $this->cipher->seal(
                (string) $attempt->request_id,
                (string) $application->public_id,
                $requestPayload,
                $requestTruncated,
                $responsePayload,
                $responseTruncated,
            );

            GatewayCallContent::query()->updateOrCreate(
                ['gateway_call_attempt_id' => $attempt->id],
                [
                    'application_id' => $application->id,
                    'key_id' => $sealed->keyId,
                    'algorithm' => $sealed->algorithm,
                    'wrapped_data_key' => $sealed->wrappedDataKey,
                    'wrap_iv' => $sealed->wrapIv,
                    'wrap_tag' => $sealed->wrapTag,
                    'request_iv' => $sealed->requestIv,
                    'request_tag' => $sealed->requestTag,
                    'request_ciphertext' => $sealed->requestCiphertext,
                    'request_bytes' => $sealed->requestBytes,
                    'request_truncated' => $sealed->requestTruncated,
                    'response_iv' => $sealed->responseIv,
                    'response_tag' => $sealed->responseTag,
                    'response_ciphertext' => $sealed->responseCiphertext,
                    'response_bytes' => $sealed->responseBytes,
                    'response_truncated' => $sealed->responseTruncated,
                    'stream_chunk_count' => $streamChunks,
                    'finalized_at' => now(),
                ],
            );

            return ContentState::Stored;
        } catch (ContentEncryptionException $exception) {
            $this->metrics->recordContentCaptureSkipped('encryption_failed');
            Log::channel(config('logging.telemetry_channel', 'stack'))->error(
                'bcaigw.content.capture_failed',
                ['reason' => $exception->getMessage(), 'request_id' => $attempt->request_id],
            );

            return ContentState::Unavailable;
        } catch (Throwable) {
            // Deliberately opaque: exception context could otherwise carry content.
            $this->metrics->recordContentCaptureSkipped('storage_failed');
            Log::channel(config('logging.telemetry_channel', 'stack'))->error(
                'bcaigw.content.capture_failed',
                ['reason' => 'storage_failed', 'request_id' => $attempt->request_id],
            );

            return ContentState::Unavailable;
        }
    }

    /**
     * Explicit, audited reveal of a single call's retained content. Content is
     * never included in list or detail payloads; it must be requested through
     * this path so that every disclosure produces an audit record.
     *
     * @return array{request: ?string, response: ?string, request_truncated: bool, response_truncated: bool, request_bytes: int, response_bytes: int, stream_chunk_count: int}
     */
    public function reveal(
        GatewayCallAttempt $attempt,
        Application $application,
        User $actor,
        Request $request,
        string $reason,
    ): array {
        $content = GatewayCallContent::query()
            ->where('gateway_call_attempt_id', $attempt->id)
            ->first();

        if ($content === null) {
            $this->audit->record(
                $request,
                AuditEventType::TelemetryContentRevealed,
                AuditOutcome::Failed,
                actor: $actor,
                context: [
                    'application_public_id' => $application->public_id,
                    'request_id' => (string) $attempt->request_id,
                    'reason' => $reason,
                    'result' => 'not_available',
                ],
            );

            throw new ContentEncryptionException('No retained content is available for this call.');
        }

        try {
            $opened = $this->cipher->open(
                $content,
                (string) $attempt->request_id,
                (string) $application->public_id,
            );
        } catch (ContentEncryptionException $exception) {
            $this->audit->record(
                $request,
                AuditEventType::TelemetryContentRevealed,
                AuditOutcome::Failed,
                actor: $actor,
                context: [
                    'application_public_id' => $application->public_id,
                    'request_id' => (string) $attempt->request_id,
                    'reason' => $reason,
                    'result' => 'decryption_failed',
                ],
            );

            throw $exception;
        }

        $this->audit->record(
            $request,
            AuditEventType::TelemetryContentRevealed,
            AuditOutcome::Succeeded,
            actor: $actor,
            context: [
                'application_public_id' => $application->public_id,
                'request_id' => (string) $attempt->request_id,
                'reason' => $reason,
                'key_id' => $content->key_id,
            ],
        );

        return [
            'request' => $opened['request'],
            'response' => $opened['response'],
            'request_truncated' => (bool) $content->request_truncated,
            'response_truncated' => (bool) $content->response_truncated,
            'request_bytes' => (int) $content->request_bytes,
            'response_bytes' => (int) $content->response_bytes,
            'stream_chunk_count' => (int) $content->stream_chunk_count,
        ];
    }

    /**
     * Rewrap stored data keys under the currently active master key. Payload
     * ciphertext is untouched, so rotation is cheap and never exposes plaintext.
     */
    public function rotate(int $limit): int
    {
        $target = $this->cipher->isAvailable();
        if (! $target) {
            throw new ContentEncryptionException('No content encryption key is configured.');
        }

        $rotated = 0;
        GatewayCallContent::query()
            ->with(['attempt:id,request_id', 'application:id,public_id'])
            ->limit($limit)
            ->get()
            ->each(function (GatewayCallContent $content) use (&$rotated): void {
                if ($content->attempt === null || $content->application === null) {
                    return;
                }
                $rewrapped = $this->cipher->rewrap(
                    $content,
                    (string) $content->attempt->request_id,
                    (string) $content->application->public_id,
                );
                if ($rewrapped === null) {
                    return;
                }
                DB::transaction(fn () => $content->forceFill($rewrapped)->save());
                $rotated++;
            });

        return $rotated;
    }

    private function ceiling(Application $application, int $global): int
    {
        $applicationCeiling = $application->retention_max_content_bytes;

        return $applicationCeiling === null
            ? $global
            : max(1, min($global, (int) $applicationCeiling));
    }
}
