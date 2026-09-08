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
            $table->unsignedBigInteger('token_rate_per_minute')->nullable()->after('rate_limit_per_minute');
            $table->unsignedBigInteger('token_budget_daily')->nullable()->after('token_rate_per_minute');
            $table->decimal('cost_budget_daily', 12, 2)->nullable()->after('token_budget_monthly');
            $table->char('budget_currency', 3)->default('CAD')->after('cost_budget_monthly');
        });

        Schema::create('quota_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->char('reservation_id', 26)->index();
            $table->string('event_type')->index();
            $table->foreignId('gateway_call_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('model_pricing_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('operation')->nullable();
            $table->string('reason')->nullable();
            $table->string('minute_window');
            $table->string('day_window');
            $table->string('month_window');
            $table->unsignedInteger('application_configuration_version');
            $table->unsignedInteger('alias_configuration_version')->nullable();
            $table->unsignedInteger('target_configuration_version')->nullable();
            $table->unsignedInteger('grant_configuration_version')->nullable();
            $table->json('budget_snapshot');
            $table->bigInteger('reserved_input_tokens')->default(0);
            $table->bigInteger('reserved_output_tokens')->default(0);
            $table->bigInteger('reserved_cost_microunits')->default(0);
            $table->bigInteger('actual_input_tokens')->default(0);
            $table->bigInteger('actual_output_tokens')->default(0);
            $table->bigInteger('actual_cached_input_tokens')->default(0);
            $table->bigInteger('actual_cost_microunits')->default(0);
            $table->bigInteger('released_tokens')->default(0);
            $table->bigInteger('released_cost_microunits')->default(0);
            $table->bigInteger('adjustment_tokens')->default(0);
            $table->bigInteger('adjustment_cost_microunits')->default(0);
            $table->boolean('usage_missing')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['application_id', 'day_window', 'event_type'], 'quota_ledger_daily_projection');
            $table->index(['application_id', 'month_window', 'event_type'], 'quota_ledger_month_projection');
        });
        DB::statement(
            "CREATE UNIQUE INDEX quota_one_terminal_per_reservation
             ON quota_ledger_entries (reservation_id)
             WHERE event_type IN ('reconciled', 'recovered')"
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION bcaigw_reject_quota_ledger_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'quota_ledger_entries is append-only';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER quota_ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON quota_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION bcaigw_reject_quota_ledger_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS quota_ledger_entries_append_only ON quota_ledger_entries');
            DB::unprepared('DROP FUNCTION IF EXISTS bcaigw_reject_quota_ledger_mutation()');
        }
        Schema::dropIfExists('quota_ledger_entries');
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['token_rate_per_minute', 'token_budget_daily', 'cost_budget_daily', 'budget_currency']);
        });
    }
};
