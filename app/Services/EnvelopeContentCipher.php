<?php

namespace App\Services;

use App\Contracts\ContentKeyProvider;
use App\DTO\SealedContent;
use App\Exceptions\ContentEncryptionException;
use App\Models\GatewayCallContent;

/**
 * Envelope encryption for retained prompt/response content.
 *
 * Each record gets a fresh AES-256-GCM data key. The data key is wrapped with
 * the active master key so that key rotation only rewraps small key blobs, and
 * so that destroying the wrapped key crypto-shreds the payload (including any
 * copy that survives in a database backup).
 *
 * Additional authenticated data binds every ciphertext to its key id, request
 * id, owning application and field name, so ciphertext cannot be replayed into
 * another row or another field without failing authentication.
 */
final readonly class EnvelopeContentCipher
{
    public function __construct(private ContentKeyProvider $keys) {}

    public function isAvailable(): bool
    {
        return $this->keys->isConfigured();
    }

    public function seal(
        string $requestId,
        string $applicationPublicId,
        ?string $requestPlaintext,
        bool $requestTruncated,
        ?string $responsePlaintext,
        bool $responseTruncated,
    ): SealedContent {
        $keyId = $this->keys->activeKeyId();
        if ($keyId === null) {
            throw new ContentEncryptionException('No content encryption key is configured.');
        }
        $algorithm = (string) config('telemetry.keys.algorithm');
        $dataKey = random_bytes(32);

        $wrapIv = random_bytes(12);
        $wrapTag = '';
        $wrapped = openssl_encrypt(
            $dataKey,
            $algorithm,
            $this->keys->key($keyId),
            OPENSSL_RAW_DATA,
            $wrapIv,
            $wrapTag,
            $this->aad($keyId, $requestId, $applicationPublicId, 'datakey'),
            16,
        );
        if ($wrapped === false) {
            throw new ContentEncryptionException('The content data key could not be wrapped.');
        }

        [$requestIv, $requestTag, $requestCipher] = $this->encryptField(
            $requestPlaintext,
            $algorithm,
            $dataKey,
            $this->fieldAad($requestId, $applicationPublicId, 'request'),
        );
        [$responseIv, $responseTag, $responseCipher] = $this->encryptField(
            $responsePlaintext,
            $algorithm,
            $dataKey,
            $this->fieldAad($requestId, $applicationPublicId, 'response'),
        );
        $this->wipe($dataKey);

        return new SealedContent(
            keyId: $keyId,
            algorithm: $algorithm,
            wrappedDataKey: base64_encode($wrapped),
            wrapIv: base64_encode($wrapIv),
            wrapTag: base64_encode($wrapTag),
            requestIv: $requestIv,
            requestTag: $requestTag,
            requestCiphertext: $requestCipher,
            requestBytes: $requestPlaintext !== null ? strlen($requestPlaintext) : 0,
            requestTruncated: $requestTruncated,
            responseIv: $responseIv,
            responseTag: $responseTag,
            responseCiphertext: $responseCipher,
            responseBytes: $responsePlaintext !== null ? strlen($responsePlaintext) : 0,
            responseTruncated: $responseTruncated,
        );
    }

    /**
     * @return array{request: ?string, response: ?string}
     */
    public function open(GatewayCallContent $content, string $requestId, string $applicationPublicId): array
    {
        $dataKey = $this->unwrap($content, $requestId, $applicationPublicId);

        $result = [
            'request' => $this->decryptField(
                $content->request_ciphertext,
                $content->request_iv,
                $content->request_tag,
                $content->algorithm,
                $dataKey,
                $this->fieldAad($requestId, $applicationPublicId, 'request'),
            ),
            'response' => $this->decryptField(
                $content->response_ciphertext,
                $content->response_iv,
                $content->response_tag,
                $content->algorithm,
                $dataKey,
                $this->fieldAad($requestId, $applicationPublicId, 'response'),
            ),
        ];
        $this->wipe($dataKey);

        return $result;
    }

    /**
     * Rewrap an existing data key under the active master key without ever
     * decrypting the payload itself.
     *
     * @return array{key_id: string, wrapped_data_key: string, wrap_iv: string, wrap_tag: string}|null
     */
    public function rewrap(GatewayCallContent $content, string $requestId, string $applicationPublicId): ?array
    {
        $target = $this->keys->activeKeyId();
        if ($target === null) {
            throw new ContentEncryptionException('No content encryption key is configured.');
        }
        if ($target === $content->key_id) {
            return null;
        }

        $dataKey = $this->unwrap($content, $requestId, $applicationPublicId);
        $iv = random_bytes(12);
        $tag = '';
        $wrapped = openssl_encrypt(
            $dataKey,
            $content->algorithm,
            $this->keys->key($target),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($target, $requestId, $applicationPublicId, 'datakey'),
            16,
        );
        $this->wipe($dataKey);
        if ($wrapped === false) {
            throw new ContentEncryptionException('The content data key could not be rewrapped.');
        }

        return [
            'key_id' => $target,
            'wrapped_data_key' => base64_encode($wrapped),
            'wrap_iv' => base64_encode($iv),
            'wrap_tag' => base64_encode($tag),
        ];
    }

    private function unwrap(GatewayCallContent $content, string $requestId, string $applicationPublicId): string
    {
        $dataKey = openssl_decrypt(
            $this->decode($content->wrapped_data_key),
            $content->algorithm,
            $this->keys->key($content->key_id),
            OPENSSL_RAW_DATA,
            $this->decode($content->wrap_iv),
            $this->decode($content->wrap_tag),
            $this->aad($content->key_id, $requestId, $applicationPublicId, 'datakey'),
        );
        if ($dataKey === false || strlen($dataKey) !== 32) {
            throw new ContentEncryptionException('The retained content key failed authentication.');
        }

        return $dataKey;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function encryptField(?string $plaintext, string $algorithm, string $key, string $aad): array
    {
        if ($plaintext === null) {
            return [null, null, null];
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, $algorithm, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($cipher === false) {
            throw new ContentEncryptionException('Retained content could not be encrypted.');
        }

        return [base64_encode($iv), base64_encode($tag), base64_encode($cipher)];
    }

    private function decryptField(
        ?string $ciphertext,
        ?string $iv,
        ?string $tag,
        string $algorithm,
        string $key,
        string $aad,
    ): ?string {
        if ($ciphertext === null || $iv === null || $tag === null) {
            return null;
        }
        $plaintext = openssl_decrypt(
            $this->decode($ciphertext),
            $algorithm,
            $key,
            OPENSSL_RAW_DATA,
            $this->decode($iv),
            $this->decode($tag),
            $aad,
        );
        if ($plaintext === false) {
            throw new ContentEncryptionException('Retained content failed authentication and was not returned.');
        }

        return $plaintext;
    }

    /**
     * Best-effort zeroing of derived key material. PHP strings are copy-on-write
     * so this is defence in depth, not a guarantee.
     */
    private function wipe(string &$key): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);

            return;
        }

        $key = str_repeat("\0", strlen($key));
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new ContentEncryptionException('Retained content is not valid base64.');
        }

        return $decoded;
    }

    private function aad(string $keyId, string $requestId, string $applicationPublicId, string $field): string
    {
        return implode('|', ['bcaigw.content.v1', $keyId, $applicationPublicId, $requestId, $field]);
    }

    /**
     * Payload AAD deliberately excludes the master key id: rotation rewraps the
     * data key under a new master key without touching payload ciphertext, so
     * binding the payload to the master key id would break every record on
     * rotation. The wrapped data key itself is still bound to its key id.
     */
    private function fieldAad(string $requestId, string $applicationPublicId, string $field): string
    {
        return implode('|', ['bcaigw.content.v1', $applicationPublicId, $requestId, $field]);
    }
}
