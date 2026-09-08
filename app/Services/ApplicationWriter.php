<?php

namespace App\Services;

use App\Enums\ApplicationMemberRole;
use App\Enums\ApplicationStatus;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\Application;
use App\Models\ApplicationLifecycleHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class ApplicationWriter
{
    public function __construct(
        private ApplicationLifecycleService $lifecycle,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $owner, Request $request): Application
    {
        return DB::transaction(function () use ($attributes, $owner, $request): Application {
            $application = new Application;
            $application->forceFill([
                ...$attributes,
                'status' => ApplicationStatus::Draft,
                'status_version' => 0,
                'prompt_response_retention_enabled' => true,
                'configuration_ready' => false,
                'created_by' => $owner->id,
            ])->save();

            $application->users()->attach($owner->id, [
                'role' => ApplicationMemberRole::Owner->value,
                'created_by' => $owner->id,
            ]);

            ApplicationLifecycleHistory::create([
                'application_id' => $application->id,
                'from_status' => null,
                'to_status' => ApplicationStatus::Draft,
                'actor_user_id' => $owner->id,
                'metadata' => ['transition' => 'created'],
            ]);

            $this->audit->record(
                $request,
                AuditEventType::ApplicationCreated,
                AuditOutcome::Succeeded,
                actor: $owner,
                context: ['application_public_id' => $application->public_id],
            );

            return $application;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        Application $application,
        array $attributes,
        User $actor,
        Request $request,
        int $expectedVersion,
    ): Application {
        return DB::transaction(function () use (
            $application,
            $attributes,
            $actor,
            $request,
            $expectedVersion,
        ): Application {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $this->lifecycle->assertVersion($locked, $expectedVersion);

            if (! $locked->status->ownerEditable()) {
                abort(403);
            }

            $locked->forceFill([
                ...$attributes,
                'status_version' => $locked->status_version + 1,
            ])->save();

            $this->audit->record(
                $request,
                AuditEventType::ApplicationUpdated,
                AuditOutcome::Succeeded,
                actor: $actor,
                context: ['application_public_id' => $locked->public_id],
            );

            return $locked->refresh();
        });
    }
}
