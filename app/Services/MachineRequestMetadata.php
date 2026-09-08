<?php

namespace App\Services;

use Illuminate\Http\Request;

final class MachineRequestMetadata
{
    public function ipHash(Request $request): ?string
    {
        $ipAddress = $request->ip();

        return $ipAddress
            ? hash_hmac('sha256', $ipAddress, (string) config('app.key'))
            : null;
    }
}
