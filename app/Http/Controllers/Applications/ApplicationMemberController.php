<?php

namespace App\Http\Controllers\Applications;

use App\Enums\ApplicationMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplicationMemberRequest;
use App\Models\Application;
use App\Models\User;
use App\Services\ApplicationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplicationMemberController extends Controller
{
    public function store(
        ApplicationMemberRequest $request,
        Application $application,
        ApplicationMembershipService $memberships,
    ): RedirectResponse {
        $validated = $request->validated();
        $member = User::query()->where('idir_username', $validated['idir_username'])->firstOrFail();

        $memberships->addOrUpdate(
            $application,
            $member,
            ApplicationMemberRole::from($validated['role']),
            $request->user(),
            $request,
        );

        return back();
    }

    public function destroy(
        Request $request,
        Application $application,
        User $user,
        ApplicationMembershipService $memberships,
    ): RedirectResponse {
        $this->authorize('manageMembers', $application);
        $memberships->remove($application, $user, $request->user(), $request);

        return back();
    }
}
