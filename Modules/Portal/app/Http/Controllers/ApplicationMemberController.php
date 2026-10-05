<?php

namespace Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Portal\Http\Requests\ApplicationMemberRequest;

class ApplicationMemberController extends Controller
{
    public function store(ApplicationMemberRequest $request, Application $application): RedirectResponse
    {
        $validated = $request->validated();
        $member = User::query()->where('idir_username', $validated['idir_username'])->firstOrFail();

        $application->users()->syncWithoutDetaching([
            $member->id => [
                'role' => $validated['role'],
                'created_by' => $request->user()->id,
            ],
        ]);

        $application->users()->updateExistingPivot($member->id, ['role' => $validated['role']]);

        return back()->with('success', 'Member saved.');
    }

    public function destroy(Request $request, Application $application, User $user): RedirectResponse
    {
        $this->authorize('manageMembers', $application);

        $application->users()->detach($user->id);

        return back()->with('success', 'Member removed.');
    }
}
