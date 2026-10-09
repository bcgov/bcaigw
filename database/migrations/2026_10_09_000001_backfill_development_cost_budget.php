<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Give existing development environments without a cost cap the default budget.
        DB::table('application_environments')
            ->where('environment', 'development')
            ->whereNull('cost_budget_monthly')
            ->update([
                'cost_budget_monthly' => config('gateway.environment_defaults.development.cost_budget_monthly', 1000),
                'budget_currency' => config('gateway.default_budget_currency', 'USD'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data backfill; intentionally not reversed.
    }
};
