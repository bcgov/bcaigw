<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name');
            $table->string('type')->index();
            $table->string('environment')->index();
            $table->string('region')->nullable();
            $table->string('secret_reference')->nullable();
            $table->json('configuration')->nullable();
            $table->text('sensitive_configuration')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('upstream_targets', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('provider_account_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('environment')->index();
            $table->string('region')->nullable();
            $table->string('base_url', 2048);
            $table->string('provider_model_identifier');
            $table->json('capabilities');
            $table->unsignedInteger('context_window');
            $table->unsignedInteger('max_input_tokens');
            $table->unsignedInteger('max_output_tokens');
            $table->string('health_status')->default('unknown')->index();
            $table->string('status')->default('active')->index();
            $table->unsignedSmallInteger('timeout_seconds')->default(30);
            $table->json('connection_settings')->nullable();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->timestampTz('last_health_checked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('public_model_aliases', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('model_id')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->json('capabilities');
            $table->foreignId('active_target_id')->nullable()
                ->constrained('upstream_targets')->restrictOnDelete();
            $table->string('status')->default('disabled')->index();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('model_alias_target_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('public_model_alias_id')->constrained()->restrictOnDelete();
            $table->foreignId('upstream_target_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('configuration_version');
            $table->timestampTz('effective_at');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->unique(['public_model_alias_id', 'configuration_version']);
        });

        Schema::create('model_pricing_versions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('public_model_alias_id')->constrained()->restrictOnDelete();
            $table->timestampTz('effective_at')->index();
            $table->char('currency', 3);
            $table->decimal('input_cost_per_million_tokens', 18, 8);
            $table->decimal('output_cost_per_million_tokens', 18, 8);
            $table->decimal('cached_input_cost_per_million_tokens', 18, 8)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['public_model_alias_id', 'effective_at']);
        });

        Schema::create('application_model_grants', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('public_model_alias_id')->constrained()->restrictOnDelete();
            $table->json('capabilities');
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('configuration_version')->default(1);
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['application_id', 'public_model_alias_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION bcaigw_reject_model_history_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'model control-plane history is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER model_alias_target_versions_append_only
                BEFORE UPDATE OR DELETE ON model_alias_target_versions
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_model_history_mutation();

                CREATE TRIGGER model_pricing_versions_append_only
                BEFORE UPDATE OR DELETE ON model_pricing_versions
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_model_history_mutation();

                CREATE OR REPLACE FUNCTION bcaigw_reject_control_plane_deletion() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'control-plane records must be disabled or retired, not deleted';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER provider_accounts_no_delete
                BEFORE DELETE ON provider_accounts
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_control_plane_deletion();

                CREATE TRIGGER upstream_targets_no_delete
                BEFORE DELETE ON upstream_targets
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_control_plane_deletion();

                CREATE TRIGGER public_model_aliases_no_delete
                BEFORE DELETE ON public_model_aliases
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_control_plane_deletion();

                CREATE TRIGGER application_model_grants_no_delete
                BEFORE DELETE ON application_model_grants
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_control_plane_deletion();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS application_model_grants_no_delete ON application_model_grants');
            DB::unprepared('DROP TRIGGER IF EXISTS public_model_aliases_no_delete ON public_model_aliases');
            DB::unprepared('DROP TRIGGER IF EXISTS upstream_targets_no_delete ON upstream_targets');
            DB::unprepared('DROP TRIGGER IF EXISTS provider_accounts_no_delete ON provider_accounts');
            DB::unprepared('DROP FUNCTION IF EXISTS bcaigw_reject_control_plane_deletion()');
            DB::unprepared('DROP TRIGGER IF EXISTS model_pricing_versions_append_only ON model_pricing_versions');
            DB::unprepared('DROP TRIGGER IF EXISTS model_alias_target_versions_append_only ON model_alias_target_versions');
            DB::unprepared('DROP FUNCTION IF EXISTS bcaigw_reject_model_history_mutation()');
        }

        Schema::dropIfExists('application_model_grants');
        Schema::dropIfExists('model_pricing_versions');
        Schema::dropIfExists('model_alias_target_versions');
        Schema::dropIfExists('public_model_aliases');
        Schema::dropIfExists('upstream_targets');
        Schema::dropIfExists('provider_accounts');
    }
};
