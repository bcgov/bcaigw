<?php

namespace App\Services;

use App\Contracts\ContentKeyProvider;
use App\Contracts\PlatformSecretResolver;
use App\Exceptions\ContentEncryptionException;
use JsonException;
use Throwable;

/**
 * Resolves the content master keyring from an inline base64 map or a platform
 * secret reference (`env:NAME` / `file:name`). APP_KEY is explicitly refused as
 * master key material: content keys must be independently rotatable and must
 * survive Laravel application key rotation.
 */
final class PlatformContentKeyProvider implements ContentKeyProvider
{
    /** @var array<string, string>|null */
    private ?array $keyring = null;

    public function __construct(private readonly PlatformSecretResolver $secrets) {}

    /**
     * Generate candidate master key material. The value is printed once by the
     * console helper and is expected to be stored in a platform secret store,
     * never in source control.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function activeKeyId(): ?string
    {
        $keyring = $this->keyring();
        if ($keyring === []) {
            return null;
        }

        $configured = config('telemetry.keys.active');
        if (is_string($configured) && $configured !== '') {
            if (! array_key_exists($configured, $keyring)) {
                throw new ContentEncryptionException(
                    'The configured active content key id is not present in the keyring.',
                );
            }

            return $configured;
        }

        // Deterministic fallback so a single-key deployment needs no extra setting.
        $ids = array_keys($keyring);
        sort($ids);

        return (string) end($ids);
    }

    public function key(string $keyId): string
    {
        $keyring = $this->keyring();
        if (! array_key_exists($keyId, $keyring)) {
            throw new ContentEncryptionException('The requested content key id is not available.');
        }

        return $keyring[$keyId];
    }

    /** @return list<string> */
    public function keyIds(): array
    {
        return array_keys($this->keyring());
    }

    public function isConfigured(): bool
    {
        try {
            return $this->activeKeyId() !== null;
        } catch (ContentEncryptionException) {
            return false;
        }
    }

    /** @return array<string, string> */
    private function keyring(): array
    {
        if ($this->keyring !== null) {
            return $this->keyring;
        }

        $raw = config('telemetry.keys.keyring');
        if (! is_string($raw) || trim($raw) === '') {
            $reference = config('telemetry.keys.reference');
            $raw = is_string($reference) && $reference !== ''
                ? $this->resolveReference($reference)
                : null;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return $this->keyring = [];
        }

        return $this->keyring = $this->parse(trim($raw));
    }

    private function resolveReference(string $reference): ?string
    {
        try {
            return $this->secrets->resolve($reference);
        } catch (Throwable) {
            throw new ContentEncryptionException('The content keyring secret reference could not be resolved.');
        }
    }

    /** @return array<string, string> */
    private function parse(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ContentEncryptionException('The content keyring must be a JSON object of key id to base64 key.');
        }
        if (! is_array($decoded) || $decoded === []) {
            throw new ContentEncryptionException('The content keyring must be a non-empty JSON object.');
        }

        $appKey = (string) config('app.key');
        $appKeyMaterial = str_starts_with($appKey, 'base64:')
            ? base64_decode(substr($appKey, 7), true)
            : $appKey;

        $keyring = [];
        foreach ($decoded as $keyId => $value) {
            if (! is_string($keyId)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $keyId) !== 1) {
                throw new ContentEncryptionException('Content key ids must be short alphanumeric identifiers.');
            }
            if (! is_string($value)) {
                throw new ContentEncryptionException('Content keys must be base64 encoded strings.');
            }
            $material = base64_decode($value, true);
            if ($material === false || strlen($material) !== 32) {
                throw new ContentEncryptionException('Content keys must decode to exactly 32 bytes.');
            }
            if ($appKeyMaterial !== false && $appKeyMaterial !== '' && hash_equals($appKeyMaterial, $material)) {
                throw new ContentEncryptionException(
                    'APP_KEY must not be reused as a retained content encryption key.',
                );
            }
            $keyring[$keyId] = $material;
        }

        return $keyring;
    }
}
