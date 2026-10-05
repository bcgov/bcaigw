<?php

namespace App\Services\Gateway;

use RuntimeException;

/**
 * Signals a gateway request that must be rejected with an OpenAI-style error body.
 */
class GatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 403,
        public readonly string $errorCode = 'gateway_forbidden',
        public readonly string $errorType = 'invalid_request_error',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{error: array{message: string, type: string, code: string}}
     */
    public function toBody(): array
    {
        return [
            'error' => [
                'message' => $this->getMessage(),
                'type' => $this->errorType,
                'code' => $this->errorCode,
            ],
        ];
    }
}
