<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Enums\PortalRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecurityAuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class UserRoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Portal/Admin', [
            'users' => User::query()
                ->select(['id', 'name', 'idir_username', 'portal_role'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function update(
        Request $request,
        User $user,
        SecurityAuditRecorder $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'portal_role' => ['required', Rule::enum(PortalRole::class)],
        ]);
        $newRole = PortalRole::from($validated['portal_role']);

        DB::transaction(function () use ($user, $newRole, $request, $audit): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $previousRole = $lockedUser->portal_role;

            if ($previousRole === PortalRole::Administrator
                && $newRole !== PortalRole::Administrator
                && User::query()
                    ->where('portal_role', PortalRole::Administrator->value)
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count() === 1) {
                throw ValidationException::withMessages([
                    'portal_role' => 'The final administrator cannot be demoted.',
                ]);
            }

            $lockedUser->portal_role = $newRole;
            $lockedUser->save();

            $audit->record(
                $request,
                AuditEventType::AdminRoleChanged,
                AuditOutcome::Succeeded,
                actor: $request->user(),
                subject: $lockedUser,
                context: [
                    'previous_role' => $previousRole->value,
                    'new_role' => $newRole->value,
                ],
            );
        });

        return back();
    }
}
