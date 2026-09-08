<?php

namespace App\Services;

use RuntimeException;

final class MachineSecretHasher
{
    private static ?string $dummyHash = null;

    public function make(string $secret): string
    {
        $hash = password_hash($secret, PASSWORD_ARGON2ID, config('machine-auth.argon2id'));

        if ($hash === false) {
            throw new RuntimeException('Unable to hash the machine credential secret.');
        }

        return $hash;
    }

    public function verify(string $secret, ?string $hash): bool
    {
        $candidate = $hash ?? self::$dummyHash ??= $this->make(
            'bcaigw-invalid-client-dummy-secret',
        );
        $valid = password_verify($secret, $candidate);

        return $hash !== null && $valid;
    }
}
