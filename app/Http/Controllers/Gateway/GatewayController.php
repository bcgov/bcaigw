<?php

namespace App\Http\Controllers\Gateway;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\GatewayCallAttempt;
use App\Models\GatewayUsageRollup;
use App\Services\Gateway\Forwarders\ChatForwarderManager;
use App\Services\Gateway\GatewayAccessGuard;
use App\Services\Gateway\GatewayException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GatewayController extends Controller
{
    public function __construct(
        private readonly GatewayAccessGuard $guard,
        private readonly ChatForwarderManager $forwarder,
    ) {}

    /**
     * OpenAI-compatible list of the models this application may invoke.
     */
    public function models(Request $request): JsonResponse
    {
        $application = $this->application($request);

        $data = $application->modelGrants()
            ->where('enabled', true)
            ->with('alias:id,model_id,display_name')
            ->get()
            ->map(fn ($grant) => [
                'id' => $grant->alias?->model_id,
                'object' => 'model',
                'owned_by' => 'bcaigw',
                'capabilities' => $grant->capabilities,
            ])
            ->filter(fn ($model) => $model['id'] !== null)
            ->values();

        return response()->json(['object' => 'list', 'data' => $data]);
    }

    /**
     * OpenAI-compatible chat completion. Authorizes the call, forwards it to the
     * resolved upstream target, records telemetry, and returns the reply.
     */
    public function chatCompletions(Request $request): JsonResponse
    {
        $application = $this->application($request);

        $validated = $request->validate([
            'model' => ['required', 'string'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:system,user,assistant'],
            'messages.*.content' => ['required'],
            'environment' => ['nullable', 'string'],
            'stream' => ['nullable', 'boolean'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:32000'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'top_p' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'reasoning_effort' => ['nullable', 'string', 'in:low,medium,high'],
        ]);

        if (! empty($validated['stream'])) {
            return $this->error('Streaming responses are not yet supported by this gateway.', 400, 'streaming_unsupported');
        }

        $environment = $validated['environment'] ?? $request->header('X-BCAIGW-Environment');

        try {
            $resolved = $this->guard->authorize($application, $validated['model'], 'chat', $environment);
        } catch (GatewayException $e) {
            return response()->json($e->toBody(), $e->status);
        }

        $messages = $this->normalizeMessages($validated['messages']);

        // Reject features the resolved model does not advertise, so callers get a
        // clear 422 instead of the model silently ignoring an image, file or
        // reasoning hint.
        $capabilityError = $this->guardCapabilities(
            (array) ($resolved['target']->capabilities ?? []),
            $messages,
            $validated['reasoning_effort'] ?? null,
        );

        if ($capabilityError !== null) {
            return $this->error($capabilityError, 422, 'capability_unsupported');
        }

        $options = array_filter([
            'max_tokens' => $validated['max_tokens'] ?? null,
            'temperature' => $validated['temperature'] ?? null,
            'top_p' => $validated['top_p'] ?? null,
            'reasoning_effort' => $validated['reasoning_effort'] ?? null,
        ], fn ($value) => $value !== null);

        $requestId = (string) Str::uuid();
        $attempt = $this->openAttempt($requestId, $application, $resolved);

        $result = $this->forwarder->forward($resolved['target'], $messages, $options);

        $this->finalizeAttempt($attempt, $result);
        $this->recordUsage($application, $resolved, $result);

        if (! $result['ok']) {
            return $this->error($result['error'] ?? 'The upstream model call failed.', 502, 'upstream_error');
        }

        return response()->json([
            'id' => 'chatcmpl-'.$requestId,
            'object' => 'chat.completion',
            'created' => Carbon::now()->timestamp,
            'model' => $validated['model'],
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $result['reply_text'] ?? ''],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => $result['usage']['input_tokens'],
                'completion_tokens' => $result['usage']['output_tokens'],
                'total_tokens' => $result['usage']['total_tokens'],
            ],
        ]);
    }

    /**
     * OpenAI-compatible image generation. Authorizes the call against the
     * model's image_generation capability, forwards it to the resolved upstream
     * target and returns the generated image(s).
     */
    public function imageGenerations(Request $request): JsonResponse
    {
        $application = $this->application($request);

        $validated = $request->validate([
            'model' => ['required', 'string'],
            'prompt' => ['required', 'string', 'max:4000'],
            'environment' => ['nullable', 'string'],
            'n' => ['nullable', 'integer', 'min:1', 'max:4'],
            'size' => ['nullable', 'string'],
            'quality' => ['nullable', 'string'],
            'style' => ['nullable', 'string'],
            'response_format' => ['nullable', 'string', 'in:b64_json,url'],
        ]);

        $environment = $validated['environment'] ?? $request->header('X-BCAIGW-Environment');

        try {
            $resolved = $this->guard->authorize($application, $validated['model'], 'image_generation', $environment);
        } catch (GatewayException $e) {
            return response()->json($e->toBody(), $e->status);
        }

        $options = array_filter([
            'n' => $validated['n'] ?? null,
            'size' => $validated['size'] ?? null,
            'quality' => $validated['quality'] ?? null,
            'style' => $validated['style'] ?? null,
            'response_format' => $validated['response_format'] ?? null,
        ], fn ($value) => $value !== null);

        $requestId = (string) Str::uuid();
        $attempt = $this->openAttempt($requestId, $application, $resolved, 'images.generations');

        $result = $this->forwarder->forwardImage($resolved['target'], $validated['prompt'], $options);

        $this->finalizeAttempt($attempt, $result);
        $this->recordUsage($application, $resolved, $result);

        if (! $result['ok']) {
            return $this->error($result['error'] ?? 'The upstream image call failed.', 502, 'upstream_error');
        }

        return response()->json([
            'created' => Carbon::now()->timestamp,
            'data' => $result['images'],
        ]);
    }

    /**
     * OpenAI-compatible embeddings. Authorizes the call against the model's
     * embeddings capability, forwards the input to the resolved upstream target
     * and returns the embedding vectors.
     */
    public function embeddings(Request $request): JsonResponse
    {
        $application = $this->application($request);

        $validated = $request->validate([
            'model' => ['required', 'string'],
            'input' => ['required'],
            'environment' => ['nullable', 'string'],
            'encoding_format' => ['nullable', 'string', 'in:float,base64'],
            'dimensions' => ['nullable', 'integer', 'min:1'],
        ]);

        $environment = $validated['environment'] ?? $request->header('X-BCAIGW-Environment');

        try {
            $resolved = $this->guard->authorize($application, $validated['model'], 'embeddings', $environment);
        } catch (GatewayException $e) {
            return response()->json($e->toBody(), $e->status);
        }

        $options = array_filter([
            'encoding_format' => $validated['encoding_format'] ?? null,
            'dimensions' => $validated['dimensions'] ?? null,
        ], fn ($value) => $value !== null);

        $requestId = (string) Str::uuid();
        $attempt = $this->openAttempt($requestId, $application, $resolved, 'embeddings');

        $result = $this->forwarder->forwardEmbeddings($resolved['target'], $validated['input'], $options);

        $this->finalizeAttempt($attempt, $result);
        $this->recordUsage($application, $resolved, $result);

        if (! $result['ok']) {
            return $this->error($result['error'] ?? 'The upstream embeddings call failed.', 502, 'upstream_error');
        }

        return response()->json([
            'object' => 'list',
            'data' => array_map(fn (array $embedding) => [
                'object' => 'embedding',
                'index' => $embedding['index'],
                'embedding' => $embedding['embedding'],
            ], $result['embeddings']),
            'model' => $validated['model'],
            'usage' => [
                'prompt_tokens' => $result['usage']['input_tokens'],
                'total_tokens' => $result['usage']['total_tokens'],
            ],
        ]);
    }

    /**
     * Reranks a set of documents against a query. Reranking uses Bedrock's
     * dedicated rerank API (not chat/converse), so it is exposed on its own
     * endpoint and authorized against the model's rerank capability.
     */
    public function rerank(Request $request): JsonResponse
    {
        $application = $this->application($request);

        $validated = $request->validate([
            'model' => ['required', 'string'],
            'query' => ['required', 'string', 'max:4000'],
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['required', 'string'],
            'environment' => ['nullable', 'string'],
            'top_n' => ['nullable', 'integer', 'min:1'],
        ]);

        $environment = $validated['environment'] ?? $request->header('X-BCAIGW-Environment');

        try {
            $resolved = $this->guard->authorize($application, $validated['model'], 'rerank', $environment);
        } catch (GatewayException $e) {
            return response()->json($e->toBody(), $e->status);
        }

        $options = array_filter([
            'top_n' => $validated['top_n'] ?? null,
        ], fn ($value) => $value !== null);

        $requestId = (string) Str::uuid();
        $attempt = $this->openAttempt($requestId, $application, $resolved, 'rerank');

        $result = $this->forwarder->forwardRerank($resolved['target'], $validated['query'], $validated['documents'], $options);

        $this->finalizeAttempt($attempt, $result);
        $this->recordUsage($application, $resolved, $result);

        if (! $result['ok']) {
            return $this->error($result['error'] ?? 'The upstream rerank call failed.', 502, 'upstream_error');
        }

        return response()->json([
            'object' => 'list',
            'model' => $validated['model'],
            'results' => $result['results'],
        ]);
    }

    private function application(Request $request): Application
    {
        /** @var Application $application */
        $application = $request->attributes->get('gateway_application');

        return $application;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array{role: string, content: string}>
     */
    private function normalizeMessages(array $messages): array
    {
        return array_map(function (array $message): array {
            $content = $message['content'];

            // Preserve OpenAI content blocks (text/image_url/file) so multimodal
            // and document inputs reach the model; only cast scalars to string.
            if (is_array($content)) {
                return ['role' => (string) $message['role'], 'content' => array_values($content)];
            }

            return ['role' => (string) $message['role'], 'content' => (string) $content];
        }, $messages);
    }

    /**
     * Ensures the resolved model advertises every capability the request relies
     * on. Returns an error message when a required capability is missing, or null
     * when the request is within the model's capabilities.
     *
     * @param  list<string>  $capabilities
     * @param  array<int, array{role: string, content: string|array<int, mixed>}>  $messages
     */
    private function guardCapabilities(array $capabilities, array $messages, ?string $reasoningEffort): ?string
    {
        $needsVision = false;
        $needsPdf = false;

        foreach ($messages as $message) {
            if (! is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as $block) {
                $type = is_array($block) ? ($block['type'] ?? null) : null;

                if ($type === 'image_url') {
                    $needsVision = true;
                } elseif ($type === 'file' || $type === 'input_file') {
                    $needsPdf = true;
                }
            }
        }

        if ($needsVision && ! in_array('vision', $capabilities, true)) {
            return 'The selected model does not support image inputs.';
        }

        if ($needsPdf && ! in_array('pdf', $capabilities, true)) {
            return 'The selected model does not support file or PDF inputs.';
        }

        if ($reasoningEffort !== null && ! in_array('reasoning', $capabilities, true)) {
            return 'The selected model does not support reasoning effort.';
        }

        return null;
    }

    /**
     * @param  array{alias: \App\Models\PublicModelAlias, target: \App\Models\UpstreamTarget, grant: \App\Models\ApplicationModelGrant, pricing: ?\App\Models\ModelPricingVersion}  $resolved
     */
    private function openAttempt(string $requestId, Application $application, array $resolved, string $operation = 'chat.completions'): ?GatewayCallAttempt
    {
        // model_pricing_version_id is a required FK; without pricing we skip telemetry rather than fail the call.
        if ($resolved['pricing'] === null) {
            return null;
        }

        try {
            return GatewayCallAttempt::create([
                'request_id' => $requestId,
                'application_id' => $application->id,
                'environment' => $resolved['environment']->environment,
                'public_model_alias_id' => $resolved['alias']->id,
                'upstream_target_id' => $resolved['target']->id,
                'model_pricing_version_id' => $resolved['pricing']->id,
                'operation' => $operation,
                'streaming' => false,
                'status' => 'processing',
                'alias_configuration_version' => $resolved['alias']->configuration_version,
                'target_configuration_version' => $resolved['target']->configuration_version,
                'grant_configuration_version' => $resolved['grant']->configuration_version,
                'started_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to open gateway call attempt.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array{ok: bool, http_status: ?int, latency_ms: int, reply_text: ?string, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}, error: ?string, correlation_id: ?string}  $result
     */
    private function finalizeAttempt(?GatewayCallAttempt $attempt, array $result): void
    {
        if ($attempt === null) {
            return;
        }

        try {
            $attempt->update([
                'status' => $result['ok'] ? 'success' : 'error',
                'prompt_tokens' => $result['usage']['input_tokens'],
                'completion_tokens' => $result['usage']['output_tokens'],
                'total_tokens' => $result['usage']['total_tokens'],
                'upstream_correlation_id' => $result['correlation_id'],
                'error_code' => $result['ok'] ? null : 'upstream_error',
                'completed_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to finalize gateway call attempt.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array{alias: \App\Models\PublicModelAlias, target: \App\Models\UpstreamTarget, grant: \App\Models\ApplicationModelGrant, pricing: ?\App\Models\ModelPricingVersion}  $resolved
     * @param  array{ok: bool, latency_ms: int, usage: array{input_tokens: int, output_tokens: int, total_tokens: int}}  $result
     */
    private function recordUsage(Application $application, array $resolved, array $result): void
    {
        try {
            $rollup = GatewayUsageRollup::firstOrNew([
                'bucket_date' => Carbon::now()->toDateString(),
                'application_id' => $application->id,
                'environment' => $resolved['environment']->environment,
                'public_model_alias_id' => $resolved['alias']->id,
                'provider_account_id' => $resolved['target']->provider_account_id,
                'outcome' => $result['ok'] ? 'success' : 'error',
            ]);

            $rollup->requests = (int) $rollup->requests + 1;
            $rollup->failures = (int) $rollup->failures + ($result['ok'] ? 0 : 1);
            $rollup->input_tokens = (int) $rollup->input_tokens + $result['usage']['input_tokens'];
            $rollup->output_tokens = (int) $rollup->output_tokens + $result['usage']['output_tokens'];
            $rollup->total_tokens = (int) $rollup->total_tokens + $result['usage']['total_tokens'];
            $rollup->latency_ms_sum = (int) $rollup->latency_ms_sum + $result['latency_ms'];
            $rollup->latency_ms_max = max((int) $rollup->latency_ms_max, $result['latency_ms']);
            $rollup->save();
        } catch (Throwable $e) {
            Log::warning('Failed to record gateway usage rollup.', ['error' => $e->getMessage()]);
        }
    }

    private function error(string $message, int $status, string $code): JsonResponse
    {
        return response()->json([
            'error' => ['message' => $message, 'type' => 'invalid_request_error', 'code' => $code],
        ], $status);
    }
}
