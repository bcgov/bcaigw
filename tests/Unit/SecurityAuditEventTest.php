<?php

namespace Tests\Unit;

use App\Enums\AuditEventType;
use App\Enums\AuditOutcome;
use App\Models\SecurityAuditEvent;
use App\Services\SecurityAuditRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class SecurityAuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_event_cannot_be_updated_or_deleted(): void
    {
        $event = SecurityAuditEvent::create([
            'event_type' => AuditEventType::LoginFailed,
            'outcome' => AuditOutcome::Failed,
        ]);

        try {
            $event->outcome = AuditOutcome::Succeeded;
            $event->save();
            $this->fail('Audit event update should have failed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('security_audit_events', [
                'id' => $event->id,
                'outcome' => AuditOutcome::Failed->value,
            ]);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_audit_context_rejects_token_and_secret_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SecurityAuditRecorder::class)->record(
            Request::create('/auth/idir/callback'),
            AuditEventType::LoginFailed,
            AuditOutcome::Failed,
            context: ['access_token' => 'must-not-be-recorded'],
        );
    }
}
