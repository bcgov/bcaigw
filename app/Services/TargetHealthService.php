<?php

namespace App\Services;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Enums\TargetHealthStatus;
use App\Models\UpstreamTarget;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final readonly class TargetHealthService
{
    public function __construct(
        private EndpointSecurityPolicy $endpointPolicy,
        private SecurityAuditRecorder $audit,
    ) {}

    public function check(
        UpstreamTarget $target,
        User $actor,
        Request $request,
    ): UpstreamTarget {
        $endpoint = $this->endpointPolicy->assertAllowed($target->base_url);
        $healthy = false;

        try {
            $options = [];
            $resolveEntries = $endpoint->curlResolveEntries();
            if ($resolveEntries !== []) {
                if (! defined('CURLOPT_RESOLVE')) {
                    throw new ConnectionException('The HTTP client cannot pin validated DNS addresses.');
                }
                $options['curl'] = [constant('CURLOPT_RESOLVE') => $resolveEntries];
            }

            $response = Http::withOptions($options)
                ->connectTimeout(min(5, $target->timeout_seconds))
                ->timeout($target->timeout_seconds)
                ->withoutRedirecting()
                ->head($target->base_url);
            $healthy = $response->status() < 500;
        } catch (ConnectionException) {
            $healthy = false;
        }

        $target->forceFill([
            'health_status' => $healthy
                ? TargetHealthStatus::Healthy
                : TargetHealthStatus::Unhealthy,
            'last_health_checked_at' => now(),
        ])->save();
        $this->audit->record(
            $request,
            AuditEventType::TargetHealthChecked,
            $healthy ? AuditOutcome::Succeeded : AuditOutcome::Failed,
            actor: $actor,
            context: [
                'target_public_id' => $target->public_id,
                'healthy' => $healthy,
            ],
        );

        return $target->refresh();
    }
}
