<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserRoleController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'idir_username', 'email'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'idir_username' => $user->idir_username,
                'email' => $user->email,
                'role' => $user->roles->first()?->name,
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'roles' => Role::query()->orderBy('id')->pluck('name'),
        ]);
    }

    public function update(User $user): RedirectResponse
    {
        $validated = request()->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
        ]);

        $newRole = Role::query()->where('name', $validated['role'])->firstOrFail();

        DB::transaction(function () use ($user, $newRole): void {
            $previousRole = $user->roles()->first()?->name;

            $wasAdmin = in_array($previousRole, [Role::SUPER_ADMIN, Role::Ministry_ADMIN], true);
            $willBeAdmin = in_array($newRole->name, [Role::SUPER_ADMIN, Role::Ministry_ADMIN], true);

            if ($wasAdmin && ! $willBeAdmin && $this->administratorCount() <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'The final administrator cannot be demoted.',
                ]);
            }

            $user->roles()->sync([$newRole->id]);
        });

        return back()->with('success', "Role updated for {$user->name}.");
    }

    private function administratorCount(): int
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', [Role::SUPER_ADMIN, Role::Ministry_ADMIN]))
            ->count();
    }
}
