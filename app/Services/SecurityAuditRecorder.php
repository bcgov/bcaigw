<?php

namespace App\Services;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class SecurityAuditRecorder
{
    /**
     * @param  array<string, bool|int|string|null>  $context
     */
    public function record(
        Request $request,
        AuditEventType $eventType,
        AuditOutcome $outcome,
        ?User $actor = null,
        ?User $subject = null,
        array $context = [],
    ): SecurityAuditEvent {
        foreach (array_keys($context) as $key) {
            if (preg_match('/token|secret|authorization|userinfo|password|code/i', $key) === 1) {
                throw new InvalidArgumentException("Sensitive audit context key is not permitted: {$key}");
            }
        }

        $sanitizedContext = array_map(
            static fn (bool|int|string|null $value): bool|int|string|null => is_string($value)
                ? mb_substr($value, 0, 255)
                : $value,
            $context,
        );

        return SecurityAuditEvent::create([
            'event_type' => $eventType,
            'outcome' => $outcome,
            'actor_user_id' => $actor?->getKey(),
            'subject_user_id' => $subject?->getKey(),
            'ip_address' => $request->ip(),
            'user_agent_hash' => $request->userAgent()
                ? hash('sha256', $request->userAgent())
                : null,
            'context' => $sanitizedContext ?: null,
        ]);
    }
}
