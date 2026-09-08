<?php

namespace App\Services;

use App\Enums\ApplicationTransition;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\Application;
use App\Models\ApplicationLifecycleHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ApplicationLifecycleService
{
    public function __construct(
        private ApplicationStateMachine $stateMachine,
        private SecurityAuditRecorder $audit,
    ) {}

    public function transition(
        Application $application,
        ApplicationTransition $transition,
        User $actor,
        Request $request,
        int $expectedVersion,
        ?string $note = null,
    ): Application {
        return DB::transaction(function () use (
            $application,
            $transition,
            $actor,
            $request,
            $expectedVersion,
            $note,
        ): Application {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->getKey());

            $this->assertVersion($locked, $expectedVersion);
            $target = $this->stateMachine->target($locked->status, $transition);

            if ($this->stateMachine->requiresNote($transition) && blank($note)) {
                throw ValidationException::withMessages([
                    'note' => 'A review note is required for this transition.',
                ]);
            }

            if ($transition === ApplicationTransition::Activate && ! $locked->configuration_ready) {
                throw ValidationException::withMessages([
                    'transition' => 'The application must be configuration-ready before activation.',
                ]);
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => $target,
                'status_version' => $locked->status_version + 1,
            ])->save();

            ApplicationLifecycleHistory::create([
                'application_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $target,
                'actor_user_id' => $actor->id,
                'note' => $note,
                'metadata' => ['transition' => $transition->value],
            ]);

            $this->audit->record(
                $request,
                AuditEventType::ApplicationTransitioned,
                AuditOutcome::Succeeded,
                actor: $actor,
                context: [
                    'application_public_id' => $locked->public_id,
                    'from_status' => $from->value,
                    'to_status' => $target->value,
                    'transition' => $transition->value,
                ],
            );

            return $locked->refresh();
        });
    }

    public function assertVersion(Application $application, int $expectedVersion): void
    {
        if ($application->status_version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'status_version' => 'The application changed since it was loaded. Refresh and try again.',
            ]);
        }
    }
}
