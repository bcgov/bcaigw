<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

final class ApplicationPolicy
{
    public function view(User $user, Application $application): bool
    {
        return $user->isAdministrator() || $application->hasMember($user);
    }

    public function update(User $user, Application $application): bool
    {
        return $application->ownerCanEdit() && $application->isOwner($user);
    }

    public function submit(User $user, Application $application): bool
    {
        return $application->ownerCanEdit() && $application->isOwner($user);
    }

    public function promote(User $user, Application $application): bool
    {
        return $application->status === Application::STATUS_ACTIVE && $application->isOwner($user);
    }

    public function manageMembers(User $user, Application $application): bool
    {
        return $application->isOwner($user);
    }

    public function viewCallHistory(User $user, Application $application): bool
    {
        return $user->isAdministrator() || $application->hasMember($user);
    }
}
