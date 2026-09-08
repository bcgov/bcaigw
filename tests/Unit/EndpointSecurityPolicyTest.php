<?php

namespace Tests\Unit;

use App\Contracts\HostResolver;
use App\Services\EndpointSecurityPolicy;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EndpointSecurityPolicyTest extends TestCase
{
    public function test_public_https_endpoint_is_allowed(): void
    {
        $policy = $this->policy(['models.example.com' => ['8.8.8.8']]);

        $endpoint = $policy->assertAllowed('https://models.example.com/v1');
        $this->assertSame(['models.example.com:443:8.8.8.8'], $endpoint->curlResolveEntries());
        $this->addToAssertionCount(1);
    }

    #[DataProvider('blockedEndpoints')]
    public function test_private_metadata_and_unsafe_endpoint_variants_are_rejected(
        string $url,
        array $addresses,
    ): void {
        $this->expectException(ValidationException::class);
        $this->policy([parse_url($url, PHP_URL_HOST) => $addresses])->assertAllowed($url);
    }

    public static function blockedEndpoints(): array
    {
        return [
            'loopback' => ['https://localhost/v1', ['127.0.0.1']],
            'localhost with public answer' => ['https://localhost/v1', ['8.8.8.8']],
            'private IPv4' => ['https://models.internal/v1', ['10.10.0.5']],
            'link local' => ['https://models.internal/v1', ['169.254.10.2']],
            'metadata IP' => ['https://169.254.169.254/latest', ['169.254.169.254']],
            'metadata hostname' => ['https://metadata.google.internal/v1', ['8.8.8.8']],
            'IPv6 loopback' => ['https://[::1]/v1', ['::1']],
            'mixed DNS' => ['https://models.example.com/v1', ['8.8.8.8', '10.0.0.2']],
            'plain HTTP' => ['http://models.example.com/v1', ['8.8.8.8']],
            'URL credentials' => ['https://user:pass@models.example.com/v1', ['8.8.8.8']],
        ];
    }

    public function test_private_http_requires_explicit_local_exception_and_allowlist(): void
    {
        config([
            'model-control.endpoint.allowed_hosts' => ['vllm.internal'],
            'model-control.endpoint.allow_private_development_endpoints' => true,
            'model-control.endpoint.allow_http_development_endpoints' => true,
        ]);

        $this->policy(['vllm.internal' => ['10.0.0.5']])
            ->assertAllowed('http://vllm.internal/v1');
        $this->addToAssertionCount(1);
    }

    /**
     * @param  array<string, list<string>>  $mapping
     */
    private function policy(array $mapping): EndpointSecurityPolicy
    {
        return new EndpointSecurityPolicy(new class($mapping) implements HostResolver
        {
            /**
             * @param  array<string, list<string>>  $mapping
             */
            public function __construct(private array $mapping) {}

            public function addresses(string $host): array
            {
                return $this->mapping[$host] ?? [];
            }
        });
    }
}
