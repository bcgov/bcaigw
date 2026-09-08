<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type')->index();
            $table->string('outcome')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->unsignedBigInteger('subject_user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION bcaigw_reject_audit_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'security_audit_events is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER security_audit_events_append_only
                BEFORE UPDATE OR DELETE ON security_audit_events
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_audit_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS security_audit_events_append_only ON security_audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS bcaigw_reject_audit_mutation()');
        }

        Schema::dropIfExists('security_audit_events');
    }
};
