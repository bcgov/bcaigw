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
        return $application->status->ownerEditable() && $application->isOwner($user);
    }

    public function submit(User $user, Application $application): bool
    {
        return $application->status->ownerEditable() && $application->isOwner($user);
    }

    public function manageMembers(User $user, Application $application): bool
    {
        return $application->isOwner($user);
    }

    public function manageCredentials(User $user, Application $application): bool
    {
        return $user->isAdministrator() || $application->isOwner($user);
    }

    /**
     * Call history exposes only metadata, so any member of the application may
     * read it.
     */
    public function viewCallHistory(User $user, Application $application): bool
    {
        return $user->isAdministrator() || $application->hasMember($user);
    }

    /**
     * Revealing retained prompt/response content is a stronger action than
     * reading the call list: it discloses the actual payloads the application
     * sent and received, so it is limited to administrators and application
     * owners and is always audited.
     */
    public function revealCallContent(User $user, Application $application): bool
    {
        return $user->isAdministrator() || $application->isOwner($user);
    }

    /**
     * Destroying retained content or detailed telemetry is irreversible and is
     * reserved for administrators.
     */
    public function deleteCallContent(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function administer(User $user): bool
    {
        return $user->isAdministrator();
    }
}
