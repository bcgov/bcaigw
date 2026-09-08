<?php

namespace Tests\Unit;

use App\Contracts\BedrockRuntimeGateway;
use App\Contracts\CanonicalGatewayRequest;
use App\Contracts\PlatformSecretResolver;
use App\DTO\AdapterResult;
use App\DTO\CanonicalMessage;
use App\DTO\ChatCompletionRequestData;
use App\DTO\EmbeddingRequestData;
use App\DTO\PricingDecision;
use App\DTO\ProviderRoutingContext;
use App\DTO\RoutingDecision;
use App\Enums\ApplicationEnvironment;
use App\Enums\ProviderType;
use App\Exceptions\GatewayException;
use App\Services\AwsBedrockAdapter;
use App\Services\AwsSdkBedrockRuntimeGateway;
use App\Services\OpenAiCompatibleAdapter;
use App\Services\PinnedHttpTransport;
use App\Services\ProviderCredentialHeaders;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderAdapterTest extends TestCase
{
    public function test_vllm_translation_pins_request_and_normalizes_public_model(): void
    {
        Http::fake([
            'https://models.example.com/v1/chat/completions' => Http::response([
                'id' => 'upstream-id',
                'model' => 'native-private-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Hello'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
                'provider' => 'must-not-leak',
            ]),
        ]);
        $adapter = $this->adapter(ProviderType::Vllm);
        $result = $adapter->invoke($this->decision(ProviderType::Vllm), $this->chatRequest());

        $this->assertSame('bcgov/public-model', $result->body['model']);
        $this->assertSame(5, $result->body['usage']['total_tokens']);
        $this->assertArrayNotHasKey('provider', $result->body);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://models.example.com/v1/chat/completions'
                && $request['model'] === 'native-deployment'
                && $request['max_tokens'] === 100;
        });
    }

    public function test_azure_translation_uses_resolved_header_without_returning_secret(): void
    {
        Http::fake([
            'https://models.example.com/v1/chat/completions?api-version=*' => Http::response([
                'choices' => [],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            ]),
        ]);
        $adapter = $this->adapter(ProviderType::AzureAiFoundry, 'resolved-secret');
        $decision = $this->decision(
            ProviderType::AzureAiFoundry,
            ['authentication' => 'api_key'],
            'env:AZURE_PROVIDER_KEY',
        );
        $result = $adapter->invoke($decision, $this->chatRequest());

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('api-key', 'resolved-secret'));
        $this->assertStringNotContainsString('resolved-secret', json_encode($result->body));
    }

    public function test_connection_failures_retry_only_before_a_response(): void
    {
        config(['gateway-data.safe_connect_retries' => 1]);
        Http::fakeSequence()
            ->pushFailedConnection()
            ->push([
                'choices' => [],
                'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            ]);

        $this->adapter(ProviderType::Vllm)
            ->invoke($this->decision(ProviderType::Vllm), $this->chatRequest());

        Http::assertSentCount(2);
    }

    public function test_request_timeout_is_not_retried(): void
    {
        config(['gateway-data.safe_connect_retries' => 1]);
        Http::fakeSequence()
            ->pushFailedConnection('cURL error 28: Operation timed out')
            ->push(['choices' => []]);

        try {
            $this->adapter(ProviderType::Vllm)
                ->invoke($this->decision(ProviderType::Vllm), $this->chatRequest());
            $this->fail('Expected the timeout to fail.');
        } catch (GatewayException $exception) {
            $this->assertSame('upstream_connection_failed', $exception->errorCode);
        }

        Http::assertSentCount(1);
    }

    public function test_streaming_removes_provider_fields_and_rejects_incomplete_frames(): void
    {
        Http::fakeSequence()->push(
            "data: {\"id\":\"chunk\",\"model\":\"private\",\"provider\":\"secret\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hi\",\"provider\":\"secret\"},\"finish_reason\":null}]}\n\ndata: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        );
        $events = iterator_to_array(
            $this->adapter(ProviderType::Vllm)
                ->stream($this->decision(ProviderType::Vllm), $this->chatRequest()),
        );

        $this->assertSame('bcgov/public-model', $events[0]['model']);
        $this->assertArrayNotHasKey('provider', $events[0]);
        $this->assertArrayNotHasKey('provider', $events[0]['choices'][0]['delta']);
    }

    public function test_incomplete_stream_frames_are_rejected(): void
    {
        Http::fake(['*' => Http::response('data: {"choices":[]}', 200)]);
        $this->expectException(GatewayException::class);
        iterator_to_array(
            $this->adapter(ProviderType::Vllm)
                ->stream($this->decision(ProviderType::Vllm), $this->chatRequest()),
        );
    }

    public function test_malformed_embeddings_are_rejected_instead_of_forwarded(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [['embedding' => ['provider-secret']]],
                'usage' => ['prompt_tokens' => 1],
            ]),
        ]);

        $this->expectException(GatewayException::class);
        $this->adapter(ProviderType::Vllm)->invoke(
            $this->decision(ProviderType::Vllm),
            new EmbeddingRequestData('bcgov/public-model', ['hello'], 'float', null),
        );
    }

    public function test_aws_adapter_delegates_only_typed_decision_and_request(): void
    {
        $expected = new AdapterResult(['object' => 'chat.completion'], 1, 2);
        $gateway = new class($expected) implements BedrockRuntimeGateway
        {
            public ?RoutingDecision $decision = null;

            public ?CanonicalGatewayRequest $request = null;

            public function __construct(private AdapterResult $expected) {}

            public function invoke(RoutingDecision $decision, CanonicalGatewayRequest $request): AdapterResult
            {
                $this->decision = $decision;
                $this->request = $request;

                return $this->expected;
            }

            public function test_aws_bedrock_chat_translation_normalizes_result_and_usage(): void
            {
                $handler = new MockHandler;
                $handler->append(new Result([
                    'output' => ['message' => ['content' => [['text' => 'Hello from Bedrock']]]],
                    'usage' => ['inputTokens' => 4, 'outputTokens' => 3],
                    'stopReason' => 'max_tokens',
                    '@metadata' => ['headers' => ['x-amzn-requestid' => 'aws-request-id']],
                ]));
                $client = new BedrockRuntimeClient([
                    'version' => 'latest',
                    'region' => 'ca-central-1',
                    'handler' => $handler,
                    'credentials' => false,
                ]);
                $gateway = new AwsSdkBedrockRuntimeGateway(fn (): BedrockRuntimeClient => $client);
                $result = $gateway->invoke(
                    $this->decision(ProviderType::AwsBedrock),
                    $this->chatRequest(),
                );

                $this->assertSame('bcgov/public-model', $result->body['model']);
                $this->assertSame('Hello from Bedrock', $result->body['choices'][0]['message']['content']);
                $this->assertSame('length', $result->body['choices'][0]['finish_reason']);
                $this->assertSame(7, $result->body['usage']['total_tokens']);
                $this->assertSame('aws-request-id', $result->upstreamCorrelationId);
            }

            public function stream(RoutingDecision $decision, CanonicalGatewayRequest $request): iterable
            {
                yield ['type' => 'response.completed'];
            }
        };
        $decision = $this->decision(ProviderType::AwsBedrock);
        $request = $this->chatRequest();
        $result = (new AwsBedrockAdapter($gateway))->invoke($decision, $request);

        $this->assertSame($expected, $result);
        $this->assertSame($decision, $gateway->decision);
        $this->assertSame($request, $gateway->request);
    }

    private function adapter(
        ProviderType $type,
        ?string $secret = null,
    ): OpenAiCompatibleAdapter {
        $resolver = new class($secret) implements PlatformSecretResolver
        {
            public function __construct(private ?string $secret) {}

            public function resolve(?string $reference): ?string
            {
                return $this->secret;
            }
        };
        $transport = new PinnedHttpTransport(
            app(Factory::class),
            new ProviderCredentialHeaders($resolver),
        );

        return new OpenAiCompatibleAdapter($type, $transport);
    }

    /**
     * @param  array<string, bool|int|string|null>  $configuration
     */
    private function decision(
        ProviderType $type,
        array $configuration = [],
        ?string $secretReference = null,
    ): RoutingDecision {
        return new RoutingDecision(
            applicationPublicId: '01M1G000000000000000000001',
            modelId: 'bcgov/public-model',
            targetPublicId: '01M1G000000000000000000002',
            baseUrl: 'https://models.example.com/v1',
            endpointHost: 'models.example.com',
            endpointPort: 443,
            resolvedAddresses: ['8.8.8.8'],
            providerModelIdentifier: 'native-deployment',
            capabilities: ['chat'],
            contextWindow: 128000,
            maxInputTokens: 120000,
            maxOutputTokens: 8000,
            timeoutSeconds: 30,
            connectionSettings: ['verify_tls' => true, 'max_connections' => 20],
            provider: new ProviderRoutingContext(
                '01M1G000000000000000000003',
                $type,
                ApplicationEnvironment::Development,
                'canadacentral',
                $secretReference,
                $configuration,
            ),
            pricing: new PricingDecision(
                '01M1G000000000000000000004',
                new \DateTimeImmutable,
                'CAD',
                '1.00000000',
                '2.00000000',
                null,
            ),
            aliasConfigurationVersion: 1,
            targetConfigurationVersion: 1,
            grantConfigurationVersion: 1,
        );
    }

    private function chatRequest(): ChatCompletionRequestData
    {
        return new ChatCompletionRequestData(
            'bcgov/public-model',
            [new CanonicalMessage('user', 'Hello')],
            100,
            false,
            null,
            null,
            null,
            [],
        );
    }
}
