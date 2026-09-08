<?php

namespace App\Services;

use App\Contracts\HostResolver;
use App\DTO\ValidatedEndpoint;
use Illuminate\Validation\ValidationException;

final readonly class EndpointSecurityPolicy
{
    public function __construct(private HostResolver $resolver) {}

    public function assertAllowed(string $url): ValidatedEndpoint
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            $this->reject('The upstream endpoint must be an absolute URL without credentials, query, or fragment.');
        }

        $developmentException = $this->developmentExceptionEnabled($host);
        if ($scheme !== 'https'
            && ! ($scheme === 'http'
                && $developmentException
                && config('model-control.endpoint.allow_http_development_endpoints'))) {
            $this->reject('Upstream endpoints must use HTTPS.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $this->isMetadataHost($host)) {
            $this->reject('Cloud metadata endpoints are not permitted.');
        }

        $allowedHosts = config('model-control.endpoint.allowed_hosts', []);
        if ($allowedHosts !== [] && ! $this->hostMatchesAllowlist($host, $allowedHosts)) {
            $this->reject('The upstream endpoint host is not allow-listed.');
        }

        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            $this->reject('The upstream endpoint host could not be resolved.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address) && ! $developmentException) {
                $this->reject('Private, loopback, link-local, and reserved upstream addresses are not permitted.');
            }
        }

        return new ValidatedEndpoint(
            url: $url,
            host: $host,
            port: isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80),
            addresses: $addresses,
        );
    }

    /**
     * @param  list<string>  $allowedHosts
     */
    private function hostMatchesAllowlist(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(rtrim($allowed, '.'));
            if ($host === $allowed) {
                return true;
            }
            if (str_starts_with($allowed, '*.')
                && str_ends_with($host, substr($allowed, 1))
                && $host !== substr($allowed, 2)) {
                return true;
            }
        }

        return false;
    }

    private function developmentExceptionEnabled(string $host): bool
    {
        if (! app()->environment(['local', 'testing'])
            || ! config('model-control.endpoint.allow_private_development_endpoints')) {
            return false;
        }

        return $this->hostMatchesAllowlist(
            $host,
            config('model-control.endpoint.allowed_hosts', []),
        );
    }

    private function isMetadataHost(string $host): bool
    {
        return in_array($host, [
            '169.254.169.254',
            'metadata.google.internal',
            'metadata.azure.internal',
        ], true);
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['base_url' => $message]);
    }
}
