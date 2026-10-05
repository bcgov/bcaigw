<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->first());

        return $user;
    }

    public function test_guests_are_redirected_from_home_to_login(): void
    {
        $this->get('/home')->assertRedirect('/login');
    }

    public function test_ministry_user_is_routed_to_the_portal_home(): void
    {
        $user = $this->userWithRole(Role::Ministry_USER);

        $this->actingAs($user)->get('/home')->assertRedirect(route('portal.dashboard'));
    }

    public function test_admin_user_is_routed_to_the_admin_dashboard(): void
    {
        $user = $this->userWithRole(Role::Ministry_ADMIN);

        $this->actingAs($user)->get('/home')->assertRedirect(route('admin.dashboard'));
    }

    public function test_portal_home_renders_for_authenticated_users(): void
    {
        $user = $this->userWithRole(Role::Ministry_GUEST);

        $this->actingAs($user)
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Portal/Dashboard'));
    }

    public function test_admin_dashboard_renders_for_admins(): void
    {
        $user = $this->userWithRole(Role::SUPER_ADMIN);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));
    }

    public function test_non_admins_cannot_access_the_admin_area(): void
    {
        $user = $this->userWithRole(Role::Ministry_USER);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_login_page_redirects_authenticated_users_to_their_home(): void
    {
        $user = $this->userWithRole(Role::Ministry_USER);

        $this->actingAs($user)->get('/login')->assertRedirect(route('portal.dashboard'));
    }

    public function test_users_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_default_roles_are_seeded_by_migration(): void
    {
        $this->assertDatabaseHas('roles', ['name' => Role::SUPER_ADMIN]);
        $this->assertDatabaseHas('roles', ['name' => Role::Ministry_GUEST]);
    }

    public function test_has_role_reflects_assigned_roles(): void
    {
        $user = $this->userWithRole(Role::Ministry_ADMIN);

        $this->assertTrue($user->fresh()->hasRole(Role::Ministry_ADMIN));
        $this->assertFalse($user->fresh()->hasRole(Role::SUPER_ADMIN));
    }
}
