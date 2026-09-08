<?php

namespace App\Services;

use App\Contracts\PlatformSecretResolver;
use App\Exceptions\GatewayException;

final class EnvironmentPlatformSecretResolver implements PlatformSecretResolver
{
    public function resolve(?string $reference): ?string
    {
        if ($reference === null || $reference === '' || $reference === 'workload-identity') {
            return null;
        }

        if (str_starts_with($reference, 'env:')) {
            $name = substr($reference, 4);
            if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/', $name) !== 1) {
                $this->unavailable();
            }
            $value = getenv($name);

            return is_string($value) && $value !== '' ? $value : $this->unavailable();
        }

        if (str_starts_with($reference, 'file:')) {
            $name = substr($reference, 5);
            if ($name === '' || basename($name) !== $name) {
                $this->unavailable();
            }
            $path = rtrim((string) config('gateway-data.secret_directory'), DIRECTORY_SEPARATOR)
                .DIRECTORY_SEPARATOR.$name;
            $value = is_file($path) ? file_get_contents($path) : false;

            return is_string($value) && trim($value) !== '' ? trim($value) : $this->unavailable();
        }

        return $this->unavailable();
    }

    private function unavailable(): never
    {
        throw new GatewayException(
            'server_error',
            'provider_credentials_unavailable',
            503,
            'The selected model provider is temporarily unavailable.',
        );
    }
}
