<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // One row per application + environment. Holds the environment's own
        // operational (running) status, budgets and rate limits. The promotion
        // review state is tracked separately so re-promoting a running env does
        // not disrupt it.
        Schema::create('application_environments', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('environment');
            $table->string('status')->default('pending')->index();
            $table->string('promoted_from')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->unsignedBigInteger('token_rate_per_minute')->nullable();
            $table->unsignedBigInteger('token_budget_daily')->nullable();
            $table->unsignedBigInteger('token_budget_monthly')->nullable();
            $table->decimal('cost_budget_daily', 12, 2)->nullable();
            $table->decimal('cost_budget_monthly', 12, 2)->nullable();
            $table->char('budget_currency', 3)->default('CAD');
            $table->unsignedInteger('configuration_version')->default(0);
            $table->unsignedInteger('status_version')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['application_id', 'environment']);
        });

        // A promotion request from one environment to the next. Approving it
        // applies the captured model snapshot and proposed budgets to the target
        // environment and activates it.
        Schema::create('application_environment_promotions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_environment');
            $table->string('to_environment');
            $table->string('status')->default('pending')->index();
            $table->json('models_snapshot');
            $table->json('proposed_budgets')->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at')->useCurrent();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['application_id', 'to_environment', 'status']);
        });

        Schema::table('application_model_grants', function (Blueprint $table) {
            $table->string('environment')->default('development')->after('application_id')->index();
        });
        Schema::table('application_model_grants', function (Blueprint $table) {
            $table->dropUnique('application_model_grants_application_id_public_model_alias_id_unique');
            $table->unique(['application_id', 'environment', 'public_model_alias_id'], 'app_grants_app_env_alias_unique');
        });

        Schema::table('gateway_call_attempts', function (Blueprint $table) {
            $table->string('environment')->default('development')->after('application_id')->index();
        });

        Schema::table('quota_ledger_entries', function (Blueprint $table) {
            $table->string('environment')->default('development')->after('application_id')->index();
        });

        Schema::table('gateway_usage_rollups', function (Blueprint $table) {
            $table->string('environment')->default('development')->after('application_id');
        });
        Schema::table('gateway_usage_rollups', function (Blueprint $table) {
            $table->dropUnique('gateway_usage_rollup_dimensions');
            $table->unique(
                ['bucket_date', 'application_id', 'environment', 'public_model_alias_id', 'provider_account_id', 'outcome'],
                'gateway_usage_rollup_dimensions',
            );
        });

        $this->backfill();
    }

    /**
     * Create an application_environments row for every environment each existing
     * application already uses, copying the application's single budget to each,
     * and duplicating the development model grants into every other environment.
     */
    private function backfill(): void
    {
        $applications = DB::table('applications')->get();

        foreach ($applications as $application) {
            $environments = json_decode($application->environments ?? '[]', true) ?: [];
            $environments = array_values(array_unique(array_filter(
                $environments,
                fn ($environment) => in_array($environment, ['development', 'test', 'production'], true),
            )));

            if (! in_array('development', $environments, true)) {
                array_unshift($environments, 'development');
            }

            $active = $application->status === 'active';
            $now = now();

            foreach ($environments as $environment) {
                $exists = DB::table('application_environments')
                    ->where('application_id', $application->id)
                    ->where('environment', $environment)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('application_environments')->insert([
                    'public_id' => (string) Str::ulid(),
                    'application_id' => $application->id,
                    'environment' => $environment,
                    'status' => $active ? 'active' : 'pending',
                    'promoted_from' => null,
                    'rate_limit_per_minute' => $application->rate_limit_per_minute,
                    'token_rate_per_minute' => $application->token_rate_per_minute,
                    'token_budget_daily' => $application->token_budget_daily,
                    'token_budget_monthly' => $application->token_budget_monthly,
                    'cost_budget_daily' => $application->cost_budget_daily,
                    'cost_budget_monthly' => $application->cost_budget_monthly,
                    'budget_currency' => $application->budget_currency ?: 'CAD',
                    'configuration_version' => 1,
                    'status_version' => 0,
                    'approved_by' => null,
                    'approved_at' => $active ? $now : null,
                    'submitted_at' => null,
                    'created_by' => $application->created_by,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $developmentGrants = DB::table('application_model_grants')
                ->where('application_id', $application->id)
                ->where('environment', 'development')
                ->get();

            foreach ($environments as $environment) {
                if ($environment === 'development') {
                    continue;
                }

                foreach ($developmentGrants as $grant) {
                    $duplicate = DB::table('application_model_grants')
                        ->where('application_id', $application->id)
                        ->where('environment', $environment)
                        ->where('public_model_alias_id', $grant->public_model_alias_id)
                        ->exists();

                    if ($duplicate) {
                        continue;
                    }

                    DB::table('application_model_grants')->insert([
                        'public_id' => (string) Str::ulid(),
                        'application_id' => $grant->application_id,
                        'environment' => $environment,
                        'public_model_alias_id' => $grant->public_model_alias_id,
                        'capabilities' => $grant->capabilities,
                        'enabled' => $grant->enabled,
                        'configuration_version' => $grant->configuration_version,
                        'granted_by' => $grant->granted_by,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('gateway_usage_rollups', function (Blueprint $table) {
            $table->dropUnique('gateway_usage_rollup_dimensions');
        });
        Schema::table('gateway_usage_rollups', function (Blueprint $table) {
            $table->dropColumn('environment');
            $table->unique(
                ['bucket_date', 'application_id', 'public_model_alias_id', 'provider_account_id', 'outcome'],
                'gateway_usage_rollup_dimensions',
            );
        });

        Schema::table('quota_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('environment');
        });

        Schema::table('gateway_call_attempts', function (Blueprint $table) {
            $table->dropColumn('environment');
        });

        Schema::table('application_model_grants', function (Blueprint $table) {
            $table->dropUnique('app_grants_app_env_alias_unique');
        });
        Schema::table('application_model_grants', function (Blueprint $table) {
            $table->dropColumn('environment');
            $table->unique(['application_id', 'public_model_alias_id']);
        });

        Schema::dropIfExists('application_environment_promotions');
        Schema::dropIfExists('application_environments');
    }
};
