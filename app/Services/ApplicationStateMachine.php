<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationTransition;
use Illuminate\Validation\ValidationException;

final class ApplicationStateMachine
{
    public function target(ApplicationStatus $status, ApplicationTransition $transition): ApplicationStatus
    {
        $target = match ($transition) {
            ApplicationTransition::Submit => match ($status) {
                ApplicationStatus::Draft, ApplicationStatus::Rejected => ApplicationStatus::PendingApproval,
                default => null,
            },
            ApplicationTransition::Approve => $status === ApplicationStatus::PendingApproval
                ? ApplicationStatus::Approved
                : null,
            ApplicationTransition::Reject => $status === ApplicationStatus::PendingApproval
                ? ApplicationStatus::Rejected
                : null,
            ApplicationTransition::Activate => match ($status) {
                ApplicationStatus::Approved,
                ApplicationStatus::Suspended,
                ApplicationStatus::Inactive => ApplicationStatus::Active,
                default => null,
            },
            ApplicationTransition::Suspend => $status === ApplicationStatus::Active
                ? ApplicationStatus::Suspended
                : null,
            ApplicationTransition::Deactivate => match ($status) {
                ApplicationStatus::Approved,
                ApplicationStatus::Active,
                ApplicationStatus::Suspended => ApplicationStatus::Inactive,
                default => null,
            },
        };

        if (! $target) {
            throw ValidationException::withMessages([
                'transition' => "The {$transition->value} transition is not allowed from {$status->value}.",
            ]);
        }

        return $target;
    }

    public function requiresNote(ApplicationTransition $transition): bool
    {
        return in_array($transition, [
            ApplicationTransition::Reject,
            ApplicationTransition::Suspend,
            ApplicationTransition::Deactivate,
        ], true);
    }
}
