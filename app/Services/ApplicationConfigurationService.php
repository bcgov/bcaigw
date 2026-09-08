<?php

namespace App\Services;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ApplicationConfigurationService
{
    public function __construct(
        private ApplicationLifecycleService $lifecycle,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function update(
        Application $application,
        array $configuration,
        User $administrator,
        Request $request,
        int $expectedVersion,
    ): Application {
        return DB::transaction(function () use (
            $application,
            $configuration,
            $administrator,
            $request,
            $expectedVersion,
        ): Application {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $this->lifecycle->assertVersion($locked, $expectedVersion);

            if (($configuration['configuration_ready'] ?? false)
                && collect([
                    $configuration['rate_limit_per_minute'] ?? null,
                    $configuration['token_budget_monthly'] ?? null,
                    $configuration['cost_budget_monthly'] ?? null,
                ])->contains(null)) {
                throw ValidationException::withMessages([
                    'configuration_ready' => 'Rate, token, and cost budgets are required before configuration is ready.',
                ]);
            }

            $locked->forceFill([
                ...$configuration,
                'budget_currency' => strtoupper(
                    $configuration['budget_currency'] ?? $locked->budget_currency,
                ),
                'status_version' => $locked->status_version + 1,
            ])->save();

            $this->audit->record(
                $request,
                AuditEventType::ApplicationConfigurationChanged,
                AuditOutcome::Succeeded,
                actor: $administrator,
                context: [
                    'application_public_id' => $locked->public_id,
                    'configuration_ready' => $locked->configuration_ready,
                    'retention_enabled' => $locked->prompt_response_retention_enabled,
                ],
            );

            return $locked->refresh();
        });
    }
}
