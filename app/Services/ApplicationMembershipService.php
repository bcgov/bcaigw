<?php

namespace App\Services;

use App\Enums\ApplicationMemberRole;
use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ApplicationMembershipService
{
    public function __construct(private SecurityAuditRecorder $audit) {}

    public function addOrUpdate(
        Application $application,
        User $member,
        ApplicationMemberRole $role,
        User $actor,
        Request $request,
    ): void {
        DB::transaction(function () use ($application, $member, $role, $actor, $request): void {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $currentRole = $locked->users()
                ->whereKey($member->id)
                ->value('application_user.role');

            if ($currentRole === ApplicationMemberRole::Owner->value
                && $role === ApplicationMemberRole::Member) {
                $this->assertAnotherOwner($locked, $member);
            }

            $locked->users()->syncWithoutDetaching([
                $member->id => [
                    'role' => $role->value,
                    'created_by' => $actor->id,
                ],
            ]);

            $this->recordChange($request, $locked, $actor, $member, $currentRole ? 'role_changed' : 'added', $role);
        });
    }

    public function remove(
        Application $application,
        User $member,
        User $actor,
        Request $request,
    ): void {
        DB::transaction(function () use ($application, $member, $actor, $request): void {
            $locked = Application::query()->lockForUpdate()->findOrFail($application->id);
            $role = $locked->users()
                ->whereKey($member->id)
                ->value('application_user.role');

            if (! $role) {
                throw ValidationException::withMessages([
                    'member' => 'The selected user is not an application member.',
                ]);
            }

            if ($role === ApplicationMemberRole::Owner->value) {
                $this->assertAnotherOwner($locked, $member);
            }

            $locked->users()->detach($member->id);
            $this->recordChange(
                $request,
                $locked,
                $actor,
                $member,
                'removed',
                ApplicationMemberRole::from($role),
            );
        });
    }

    private function assertAnotherOwner(Application $application, User $member): void
    {
        $otherOwners = $application->users()
            ->wherePivot('role', ApplicationMemberRole::Owner->value)
            ->whereKeyNot($member->id)
            ->lockForUpdate()
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages([
                'member' => 'An application must retain at least one owner.',
            ]);
        }
    }

    private function recordChange(
        Request $request,
        Application $application,
        User $actor,
        User $member,
        string $change,
        ApplicationMemberRole $role,
    ): void {
        $this->audit->record(
            $request,
            AuditEventType::ApplicationMemberChanged,
            AuditOutcome::Succeeded,
            actor: $actor,
            subject: $member,
            context: [
                'application_public_id' => $application->public_id,
                'change' => $change,
                'member_role' => $role->value,
            ],
        );
    }
}
