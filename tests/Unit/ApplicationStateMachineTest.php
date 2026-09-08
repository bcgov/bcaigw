<?php

namespace Tests\Unit;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationTransition;
use App\Services\ApplicationStateMachine;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplicationStateMachineTest extends TestCase
{
    #[DataProvider('validTransitions')]
    public function test_valid_transition_matrix(
        ApplicationStatus $from,
        ApplicationTransition $transition,
        ApplicationStatus $to,
    ): void {
        $this->assertSame($to, app(ApplicationStateMachine::class)->target($from, $transition));
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_matrix(
        ApplicationStatus $from,
        ApplicationTransition $transition,
    ): void {
        $this->expectException(ValidationException::class);
        app(ApplicationStateMachine::class)->target($from, $transition);
    }

    public static function validTransitions(): array
    {
        return [
            'draft submit' => [ApplicationStatus::Draft, ApplicationTransition::Submit, ApplicationStatus::PendingApproval],
            'rejected resubmit' => [ApplicationStatus::Rejected, ApplicationTransition::Submit, ApplicationStatus::PendingApproval],
            'pending approve' => [ApplicationStatus::PendingApproval, ApplicationTransition::Approve, ApplicationStatus::Approved],
            'pending reject' => [ApplicationStatus::PendingApproval, ApplicationTransition::Reject, ApplicationStatus::Rejected],
            'approved activate' => [ApplicationStatus::Approved, ApplicationTransition::Activate, ApplicationStatus::Active],
            'active suspend' => [ApplicationStatus::Active, ApplicationTransition::Suspend, ApplicationStatus::Suspended],
            'active deactivate' => [ApplicationStatus::Active, ApplicationTransition::Deactivate, ApplicationStatus::Inactive],
            'suspended reactivate' => [ApplicationStatus::Suspended, ApplicationTransition::Activate, ApplicationStatus::Active],
            'inactive reactivate' => [ApplicationStatus::Inactive, ApplicationTransition::Activate, ApplicationStatus::Active],
        ];
    }

    public static function invalidTransitions(): array
    {
        return [
            'draft approve' => [ApplicationStatus::Draft, ApplicationTransition::Approve],
            'pending activate' => [ApplicationStatus::PendingApproval, ApplicationTransition::Activate],
            'rejected activate' => [ApplicationStatus::Rejected, ApplicationTransition::Activate],
            'approved submit' => [ApplicationStatus::Approved, ApplicationTransition::Submit],
            'inactive approve' => [ApplicationStatus::Inactive, ApplicationTransition::Approve],
        ];
    }
}
