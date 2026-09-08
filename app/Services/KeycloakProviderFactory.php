<?php

namespace App\Services;

use App\Auth\Keycloak\PkceKeycloak;
use InvalidArgumentException;

final class KeycloakProviderFactory
{
    /**
     * Whether every Keycloak setting required to start a sign-in is present and
     * valid for the current environment.
     *
     * Implemented by attempting the real build rather than re-listing the
     * required keys, so this can never drift from make() and report "ready"
     * for a configuration that make() would reject.
     */
    public function isConfigured(): bool
    {
        try {
            $this->make();

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function make(): PkceKeycloak
    {
        $configuration = config('services.keycloak');

        foreach ([
            'base_url',
            'realm',
            'client_id',
            'client_secret',
            'redirect_uri',
            'post_logout_redirect_uri',
        ] as $key) {
            if (! is_string($configuration[$key] ?? null) || $configuration[$key] === '') {
                throw new InvalidArgumentException("Missing Keycloak configuration value: {$key}");
            }
        }

        foreach (['base_url', 'redirect_uri', 'post_logout_redirect_uri'] as $key) {
            if (filter_var($configuration[$key], FILTER_VALIDATE_URL) === false) {
                throw new InvalidArgumentException("Invalid Keycloak URL configuration value: {$key}");
            }
        }

        if (app()->environment('production')) {
            foreach (['base_url', 'redirect_uri', 'post_logout_redirect_uri'] as $key) {
                if (parse_url($configuration[$key], PHP_URL_SCHEME) !== 'https') {
                    throw new InvalidArgumentException("Keycloak URL must use HTTPS in production: {$key}");
                }
            }
        }

        return new PkceKeycloak([
            'authServerUrl' => rtrim($configuration['base_url'], '/'),
            'realm' => $configuration['realm'],
            'clientId' => $configuration['client_id'],
            'clientSecret' => $configuration['client_secret'],
            'redirectUri' => $configuration['redirect_uri'],
            'version' => $configuration['version'],
        ]);
    }
}
