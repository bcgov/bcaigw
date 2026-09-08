<?php

namespace Tests\Feature;

use App\Enums\AuditEventType;
use App\Enums\PortalRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_idir_sign_in(): void
    {
        $this->get('/portal')->assertRedirect(route('auth.idir.redirect'));
    }

    public function test_owner_can_access_portal_but_not_administration(): void
    {
        $owner = User::factory()->create([
            'portal_role' => PortalRole::ApplicationOwner,
        ]);

        $this->actingAs($owner)->get('/portal')->assertOk();
        $this->actingAs($owner)->get('/portal/admin')->assertForbidden();

        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::AuthorizationDenied->value,
            'actor_user_id' => $owner->id,
            'outcome' => 'denied',
        ]);
    }

    public function test_administrator_can_access_administration_and_change_roles(): void
    {
        $administrator = User::factory()->create([
            'portal_role' => PortalRole::Administrator,
        ]);
        $owner = User::factory()->create([
            'portal_role' => PortalRole::ApplicationOwner,
        ]);

        $this->actingAs($administrator)->get('/portal/admin')->assertOk();
        $this->actingAs($administrator)
            ->patch("/portal/admin/users/{$owner->id}/role", [
                'portal_role' => PortalRole::Administrator->value,
            ])
            ->assertRedirect();

        $this->assertSame(PortalRole::Administrator, $owner->refresh()->portal_role);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::AdminRoleChanged->value,
            'actor_user_id' => $administrator->id,
            'subject_user_id' => $owner->id,
        ]);
    }

    public function test_final_administrator_cannot_be_demoted(): void
    {
        $administrator = User::factory()->create([
            'portal_role' => PortalRole::Administrator,
        ]);

        $this->actingAs($administrator)
            ->patch("/portal/admin/users/{$administrator->id}/role", [
                'portal_role' => PortalRole::ApplicationOwner->value,
            ])
            ->assertSessionHasErrors('portal_role');

        $this->assertSame(PortalRole::Administrator, $administrator->refresh()->portal_role);
        $this->assertDatabaseMissing('security_audit_events', [
            'event_type' => AuditEventType::AdminRoleChanged->value,
        ]);
    }
}
