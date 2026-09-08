<?php

namespace Tests\Feature;

use App\Enums\ApplicationMemberRole;
use App\Enums\ApplicationStatus;
use App\Enums\ApplicationTransition;
use App\Enums\AuditEventType;
use App\Enums\PortalRole;
use App\Models\Application;
use App\Models\ApplicationLifecycleHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_draft_with_public_ulid_default_retention_history_and_audit(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post('/portal/applications', $this->validApplicationData())
            ->assertRedirect();

        $application = Application::query()->firstOrFail();

        $this->assertSame(26, strlen($application->public_id));
        $this->assertSame('api-directory-client-123', $application->api_directory_client_id);
        $this->assertSame(ApplicationStatus::Draft, $application->status);
        $this->assertTrue($application->prompt_response_retention_enabled);
        $this->assertFalse($application->configuration_ready);
        $this->assertTrue($application->isOwner($owner));
        $this->assertDatabaseHas('application_lifecycle_histories', [
            'application_id' => $application->id,
            'from_status' => null,
            'to_status' => ApplicationStatus::Draft->value,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::ApplicationCreated->value,
            'actor_user_id' => $owner->id,
        ]);
    }

    public function test_creation_validation_and_state_tampering_are_rejected(): void
    {
        $owner = User::factory()->create();
        $data = $this->validApplicationData();
        $data['purpose_use_case'] = 'short';
        $data['status'] = ApplicationStatus::Active->value;
        $data['prompt_response_retention_enabled'] = false;

        $this->actingAs($owner)
            ->post('/portal/applications', $data)
            ->assertSessionHasErrors([
                'purpose_use_case',
                'status',
                'prompt_response_retention_enabled',
            ]);

        $this->assertDatabaseCount('applications', 0);
    }

    public function test_api_directory_client_id_must_be_unique(): void
    {
        $owner = User::factory()->create();
        Application::factory()->create([
            'api_directory_client_id' => 'api-directory-client-123',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->post('/portal/applications', $this->validApplicationData())
            ->assertSessionHasErrors('api_directory_client_id');
    }

    public function test_cross_tenant_user_is_denied_and_member_is_read_only(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $application = $this->applicationOwnedBy($owner);
        $application->users()->attach($member->id, [
            'role' => ApplicationMemberRole::Member->value,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($member)->get(route('portal.applications.show', $application))->assertOk();
        $this->actingAs($member)->get(route('portal.applications.edit', $application))->assertForbidden();
        $this->actingAs($outsider)->get(route('portal.applications.show', $application))->assertForbidden();

        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::AuthorizationDenied->value,
            'actor_user_id' => $outsider->id,
        ]);
    }

    public function test_route_binding_uses_public_id_instead_of_internal_id(): void
    {
        $owner = User::factory()->create();
        $application = $this->applicationOwnedBy($owner);

        $this->actingAs($owner)
            ->get("/portal/applications/{$application->id}")
            ->assertNotFound();
        $this->actingAs($owner)
            ->get(route('portal.applications.show', $application))
            ->assertOk();
    }

    public function test_owner_can_manage_members_but_cannot_remove_the_final_owner(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create(['idir_username' => 'MEMBER1']);
        $application = $this->applicationOwnedBy($owner);

        $this->actingAs($owner)
            ->post(route('portal.applications.members.store', $application), [
                'idir_username' => 'member1',
                'role' => ApplicationMemberRole::Member->value,
            ])
            ->assertRedirect();

        $this->assertTrue($application->hasMember($member));
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::ApplicationMemberChanged->value,
            'subject_user_id' => $member->id,
        ]);

        $this->actingAs($owner)
            ->delete(route('portal.applications.members.destroy', [$application, $owner]))
            ->assertSessionHasErrors('member');

        $this->assertTrue($application->isOwner($owner));

        $this->actingAs($owner)
            ->post(route('portal.applications.members.store', $application), [
                'idir_username' => $owner->idir_username,
                'role' => ApplicationMemberRole::Member->value,
            ])
            ->assertSessionHasErrors('member');

        $this->assertTrue($application->isOwner($owner));
    }

    public function test_owner_submits_but_cannot_perform_admin_transitions(): void
    {
        $owner = User::factory()->create();
        $application = $this->applicationOwnedBy($owner);

        $this->actingAs($owner)
            ->post(route('portal.applications.submit', $application), [
                'transition' => ApplicationTransition::Submit->value,
                'status_version' => 0,
            ])
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::PendingApproval, $application->refresh()->status);
        $this->assertDatabaseHas('application_lifecycle_histories', [
            'application_id' => $application->id,
            'from_status' => ApplicationStatus::Draft->value,
            'to_status' => ApplicationStatus::PendingApproval->value,
        ]);

        $this->actingAs($owner)
            ->post(route('portal.admin.applications.transitions', $application), [
                'transition' => ApplicationTransition::Approve->value,
                'status_version' => 1,
            ])
            ->assertForbidden();
    }

    public function test_administrator_cannot_submit_an_owner_draft(): void
    {
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $owner = User::factory()->create();
        $application = $this->applicationOwnedBy($owner);

        $this->actingAs($administrator)
            ->post(route('portal.admin.applications.transitions', $application), [
                'transition' => ApplicationTransition::Submit->value,
                'status_version' => 0,
            ])
            ->assertForbidden();

        $this->assertSame(ApplicationStatus::Draft, $application->refresh()->status);
    }

    public function test_administrator_approves_configures_and_activates_application(): void
    {
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $owner = User::factory()->create();
        $application = $this->applicationOwnedBy($owner, ApplicationStatus::PendingApproval);

        $this->actingAs($administrator)
            ->post(route('portal.admin.applications.transitions', $application), [
                'transition' => ApplicationTransition::Approve->value,
                'status_version' => 0,
            ])
            ->assertRedirect();

        $application->refresh();
        $this->actingAs($administrator)
            ->post(route('portal.admin.applications.transitions', $application), [
                'transition' => ApplicationTransition::Activate->value,
                'status_version' => 1,
            ])
            ->assertSessionHasErrors('transition');

        $this->actingAs($administrator)
            ->patch(route('portal.admin.applications.configuration', $application), [
                'status_version' => 1,
                'prompt_response_retention_enabled' => false,
                'rate_limit_per_minute' => 120,
                'token_budget_monthly' => 2_000_000,
                'cost_budget_monthly' => 250.50,
                'configuration_ready' => true,
            ])
            ->assertRedirect();

        $application->refresh();
        $this->actingAs($administrator)
            ->post(route('portal.admin.applications.transitions', $application), [
                'transition' => ApplicationTransition::Activate->value,
                'status_version' => 2,
            ])
            ->assertRedirect();

        $application->refresh();
        $this->assertSame(ApplicationStatus::Active, $application->status);
        $this->assertFalse($application->prompt_response_retention_enabled);
        $this->assertSame(120, $application->rate_limit_per_minute);
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => AuditEventType::ApplicationConfigurationChanged->value,
            'actor_user_id' => $administrator->id,
        ]);
    }

    public function test_configuration_cannot_be_marked_ready_without_all_budgets(): void
    {
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $owner = User::factory()->create();
        $application = $this->applicationOwnedBy($owner, ApplicationStatus::Approved);

        $this->actingAs($administrator)
            ->patch(route('portal.admin.applications.configuration', $application), [
                'status_version' => 0,
                'prompt_response_retention_enabled' => true,
                'rate_limit_per_minute' => 100,
                'token_budget_monthly' => null,
                'cost_budget_monthly' => 25,
                'configuration_ready' => true,
            ])
            ->assertSessionHasErrors('configuration_ready');

        $this->assertFalse($application->refresh()->configuration_ready);
    }

    public function test_reject_suspend_and_deactivate_require_notes(): void
    {
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $owner = User::factory()->create();
        $pending = $this->applicationOwnedBy($owner, ApplicationStatus::PendingApproval);

        $this->actingAs($administrator)
            ->post(route('portal.admin.applications.transitions', $pending), [
                'transition' => ApplicationTransition::Reject->value,
                'status_version' => 0,
            ])
            ->assertSessionHasErrors('note');

        $this->actingAs($administrator)
            ->post(route('portal.admin.applications.transitions', $pending), [
                'transition' => ApplicationTransition::Reject->value,
                'status_version' => 0,
                'note' => 'Clarify the approved use case and resubmit.',
            ])
            ->assertRedirect();

        $this->assertSame(ApplicationStatus::Rejected, $pending->refresh()->status);
    }

    public function test_administrator_can_suspend_deactivate_and_reactivate_with_complete_history(): void
    {
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $owner = User::factory()->create();
        $application = $this->applicationOwnedBy($owner, ApplicationStatus::Active);
        $application->forceFill(['configuration_ready' => true])->save();

        foreach ([
            [ApplicationTransition::Suspend, 0, ApplicationStatus::Suspended, 'Operational review required.'],
            [ApplicationTransition::Activate, 1, ApplicationStatus::Active, null],
            [ApplicationTransition::Deactivate, 2, ApplicationStatus::Inactive, 'Application retired by owner request.'],
            [ApplicationTransition::Activate, 3, ApplicationStatus::Active, null],
        ] as [$transition, $version, $status, $note]) {
            $this->actingAs($administrator)
                ->post(route('portal.admin.applications.transitions', $application), [
                    'transition' => $transition->value,
                    'status_version' => $version,
                    'note' => $note,
                ])
                ->assertRedirect();

            $this->assertSame($status, $application->refresh()->status);
        }

        $this->assertSame(5, $application->lifecycleHistory()->count());
        $this->assertSame(
            4,
            $application->lifecycleHistory()->whereNotNull('from_status')->count(),
        );
    }

    public function test_stale_transition_and_stale_edit_are_rejected(): void
    {
        $administrator = User::factory()->create(['portal_role' => PortalRole::Administrator]);
        $owner = User::factory()->create();
        $application = $this->applicationOwnedBy($owner, ApplicationStatus::PendingApproval);
        $application->forceFill(['status_version' => 2])->save();

        $this->actingAs($administrator)
            ->post(route('portal.admin.applications.transitions', $application), [
                'transition' => ApplicationTransition::Approve->value,
                'status_version' => 1,
            ])
            ->assertSessionHasErrors('status_version');

        $draft = $this->applicationOwnedBy($owner);
        $editData = [...$this->validApplicationData(), 'status_version' => 5];
        $this->actingAs($owner)
            ->put(route('portal.applications.update', $draft), $editData)
            ->assertSessionHasErrors('status_version');
    }

    public function test_owner_cannot_change_admin_owned_retention_or_budget_configuration(): void
    {
        $owner = User::factory()->create();
        $application = $this->applicationOwnedBy($owner);
        $data = [
            ...$this->validApplicationData(),
            'status_version' => 0,
            'prompt_response_retention_enabled' => false,
            'rate_limit_per_minute' => 999,
        ];

        $this->actingAs($owner)
            ->put(route('portal.applications.update', $application), $data)
            ->assertSessionHasErrors([
                'prompt_response_retention_enabled',
                'rate_limit_per_minute',
            ]);

        $application->refresh();
        $this->assertTrue($application->prompt_response_retention_enabled);
        $this->assertNull($application->rate_limit_per_minute);
    }

    /**
     * @return array<string, mixed>
     */
    private function validApplicationData(): array
    {
        return [
            'api_directory_client_id' => 'api-directory-client-123',
            'name' => 'Citizen Services Assistant',
            'ministry_organization' => 'Ministry of Citizens Services',
            'purpose_use_case' => 'Assist staff with finding approved public service information.',
            'primary_contact_name' => 'Jane Smith',
            'primary_contact_email' => 'jane.smith@gov.bc.ca',
            'technical_contact_name' => 'John Smith',
            'technical_contact_email' => 'john.smith@gov.bc.ca',
            'environments' => ['development', 'test'],
            'expected_requests_per_minute' => 60,
            'expected_tokens_per_month' => 1_000_000,
            'data_classification' => 'internal',
            'requested_models' => ['azure-openai-gpt-4o'],
            'requested_capabilities' => ['chat', 'structured_output'],
        ];
    }

    private function applicationOwnedBy(
        User $owner,
        ApplicationStatus $status = ApplicationStatus::Draft,
    ): Application {
        $application = Application::factory()->create([
            'created_by' => $owner->id,
            'status' => $status,
        ]);
        $application->users()->attach($owner->id, [
            'role' => ApplicationMemberRole::Owner->value,
            'created_by' => $owner->id,
        ]);
        ApplicationLifecycleHistory::create([
            'application_id' => $application->id,
            'from_status' => null,
            'to_status' => $status,
            'actor_user_id' => $owner->id,
        ]);

        return $application;
    }
}
