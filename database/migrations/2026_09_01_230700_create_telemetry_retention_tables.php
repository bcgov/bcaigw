<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->unsignedInteger('retention_max_content_bytes')
                ->nullable()
                ->after('prompt_response_retention_enabled');
        });

        Schema::table('gateway_call_attempts', function (Blueprint $table) {
            $table->foreignId('provider_account_id')->nullable()->after('upstream_target_id')
                ->constrained()->restrictOnDelete();
            $table->string('requested_model')->nullable()->after('operation');
            $table->string('resolved_model_alias')->nullable()->after('requested_model');
            $table->unsignedInteger('application_status_version')->nullable()
                ->after('grant_configuration_version');
            $table->unsignedInteger('cached_input_tokens')->nullable()->after('completion_tokens');
            $table->bigInteger('cost_microunits')->nullable()->after('total_tokens');
            $table->char('cost_currency', 3)->nullable()->after('cost_microunits');
            $table->unsignedInteger('queue_latency_ms')->nullable()->after('cost_currency');
            $table->unsignedInteger('upstream_latency_ms')->nullable()->after('queue_latency_ms');
            $table->unsignedInteger('total_latency_ms')->nullable()->after('upstream_latency_ms');
            $table->unsignedInteger('time_to_first_token_ms')->nullable()->after('total_latency_ms');
            $table->unsignedSmallInteger('http_status')->nullable()->after('error_code');
            $table->string('error_category')->nullable()->after('http_status');
            $table->unsignedSmallInteger('retry_count')->default(0)->after('error_category');
            $table->boolean('client_cancelled')->default(false)->after('retry_count');
            $table->boolean('partial_response')->default(false)->after('client_cancelled');
            $table->json('client_metadata')->nullable()->after('partial_response');
            $table->boolean('content_retention_enabled')->default(false)->after('client_metadata');
            $table->string('content_state')->default('not_retained')->after('content_retention_enabled');
            $table->timestampTz('content_deleted_at')->nullable()->after('content_state');
            $table->timestampTz('details_redacted_at')->nullable()->after('content_deleted_at');
            $table->timestampTz('first_byte_at')->nullable()->after('started_at');
            $table->index(['application_id', 'started_at'], 'gateway_attempts_app_started');
            $table->index(['public_model_alias_id', 'started_at'], 'gateway_attempts_alias_started');
            $table->index(['status', 'started_at'], 'gateway_attempts_status_started');
            $table->index(['content_state'], 'gateway_attempts_content_state');
        });

        Schema::create('gateway_call_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_call_attempt_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->string('key_id');
            $table->string('algorithm');
            $table->text('wrapped_data_key');
            $table->string('wrap_iv');
            $table->string('wrap_tag');
            $table->string('request_iv')->nullable();
            $table->string('request_tag')->nullable();
            $table->longText('request_ciphertext')->nullable();
            $table->unsignedBigInteger('request_bytes')->default(0);
            $table->boolean('request_truncated')->default(false);
            $table->string('response_iv')->nullable();
            $table->string('response_tag')->nullable();
            $table->longText('response_ciphertext')->nullable();
            $table->unsignedBigInteger('response_bytes')->default(0);
            $table->boolean('response_truncated')->default(false);
            $table->unsignedInteger('stream_chunk_count')->default(0);
            $table->timestampTz('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'created_at'], 'gateway_contents_app_created');
            $table->index('key_id', 'gateway_contents_key_id');
        });

        Schema::create('telemetry_content_deletions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('scope');
            $table->string('mode');
            $table->text('reason');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('range_from')->nullable();
            $table->timestampTz('range_to')->nullable();
            $table->unsignedInteger('matched_attempts')->default(0);
            $table->unsignedInteger('content_records_destroyed')->default(0);
            $table->unsignedInteger('details_redacted')->default(0);
            $table->json('request_ids')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['application_id', 'created_at'], 'telemetry_deletions_app_created');
        });

        Schema::table('gateway_call_attempts', function (Blueprint $table) {
            $table->foreignId('telemetry_content_deletion_id')->nullable()->after('details_redacted_at')
                ->constrained('telemetry_content_deletions')->restrictOnDelete();
        });

        Schema::create('gateway_usage_rollups', function (Blueprint $table) {
            $table->id();
            $table->date('bucket_date');
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('public_model_alias_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_account_id')->constrained()->restrictOnDelete();
            $table->string('outcome');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('failures')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->bigInteger('cost_microunits')->default(0);
            $table->char('cost_currency', 3)->nullable();
            $table->unsignedBigInteger('latency_ms_sum')->default(0);
            $table->unsignedInteger('latency_ms_max')->default(0);
            $table->unsignedBigInteger('ttft_ms_sum')->default(0);
            $table->unsignedInteger('ttft_samples')->default(0);
            $table->timestamps();
            $table->unique(
                ['bucket_date', 'application_id', 'public_model_alias_id', 'provider_account_id', 'outcome'],
                'gateway_usage_rollup_dimensions',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION bcaigw_reject_content_deletion_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'telemetry_content_deletions is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER telemetry_content_deletions_append_only
                BEFORE UPDATE OR DELETE ON telemetry_content_deletions
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_content_deletion_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS telemetry_content_deletions_append_only ON telemetry_content_deletions'
            );
            DB::unprepared('DROP FUNCTION IF EXISTS bcaigw_reject_content_deletion_mutation()');
        }

        Schema::dropIfExists('gateway_usage_rollups');
        Schema::table('gateway_call_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telemetry_content_deletion_id');
        });
        Schema::dropIfExists('telemetry_content_deletions');
        Schema::dropIfExists('gateway_call_contents');
        Schema::table('gateway_call_attempts', function (Blueprint $table) {
            $table->dropIndex('gateway_attempts_app_started');
            $table->dropIndex('gateway_attempts_alias_started');
            $table->dropIndex('gateway_attempts_status_started');
            $table->dropIndex('gateway_attempts_content_state');
            $table->dropConstrainedForeignId('provider_account_id');
            $table->dropColumn([
                'requested_model',
                'resolved_model_alias',
                'application_status_version',
                'cached_input_tokens',
                'cost_microunits',
                'cost_currency',
                'queue_latency_ms',
                'upstream_latency_ms',
                'total_latency_ms',
                'time_to_first_token_ms',
                'http_status',
                'error_category',
                'retry_count',
                'client_cancelled',
                'partial_response',
                'client_metadata',
                'content_retention_enabled',
                'content_state',
                'content_deleted_at',
                'details_redacted_at',
                'first_byte_at',
            ]);
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('retention_max_content_bytes');
        });
    }
};
