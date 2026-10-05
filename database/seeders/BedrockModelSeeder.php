<?php

namespace Database\Seeders;

use App\Models\ModelAliasTargetVersion;
use App\Models\ModelPricingVersion;
use App\Models\ProviderAccount;
use App\Models\PublicModelAlias;
use App\Models\Role;
use App\Models\UpstreamTarget;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BedrockModelSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', Role::SUPER_ADMIN))
            ->first() ?? User::query()->first();

        if ($admin === null) {
            $this->command?->warn('No user found — run the base DatabaseSeeder first.');

            return;
        }

        $baseUrl = 'https://bedrock-runtime.ca-central-1.amazonaws.com';

        DB::transaction(function () use ($admin, $baseUrl): void {
            $provider = ProviderAccount::firstOrCreate(
                ['name' => 'AWS Bedrock (ca-central-1)'],
                [
                    'type' => 'bedrock',
                    'environment' => 'production',
                    'region' => 'ca-central-1',
                    'status' => 'active',
                    'created_by' => $admin->id,
                ],
            );

            // provider_model_identifier is the Bedrock inference-profile ID validated by live test.
            $models = [
                [
                    'target_name' => 'Amazon Nova 2 Lite (global profile)',
                    'model_identifier' => 'global.amazon.nova-2-lite-v1:0',
                    'alias_model_id' => 'amazon.nova-2-lite',
                    'alias_display' => 'Amazon Nova 2 Lite',
                    'input_cost' => '0.06000000',
                    'output_cost' => '0.24000000',
                ],
                [
                    'target_name' => 'Amazon Nova Lite (CA profile)',
                    'model_identifier' => 'ca.amazon.nova-lite-v1:0',
                    'alias_model_id' => 'amazon.nova-lite',
                    'alias_display' => 'Amazon Nova Lite',
                    'input_cost' => '0.06000000',
                    'output_cost' => '0.24000000',
                ],
            ];

            $capabilities = ['chat', 'structured_output', 'tool_use'];

            foreach ($models as $model) {
                $target = UpstreamTarget::query()
                    ->where('provider_model_identifier', $model['model_identifier'])
                    ->first();

                if ($target === null) {
                    $target = new UpstreamTarget();
                    $target->fill([
                        'provider_account_id' => $provider->id,
                        'name' => $model['target_name'],
                        'environment' => 'production',
                        'region' => 'ca-central-1',
                        'base_url' => $baseUrl,
                        'provider_model_identifier' => $model['model_identifier'],
                        'capabilities' => $capabilities,
                        'context_window' => 300000,
                        'max_input_tokens' => 300000,
                        'max_output_tokens' => 5120,
                        'status' => 'active',
                        'timeout_seconds' => 60,
                        'created_by' => $admin->id,
                    ]);
                    // health_status is guarded; set directly after the live Converse test succeeded.
                    $target->health_status = 'healthy';
                    $target->last_health_checked_at = now();
                    $target->save();
                }

                $alias = PublicModelAlias::firstOrCreate(
                    ['model_id' => $model['alias_model_id']],
                    [
                        'display_name' => $model['alias_display'],
                        'description' => 'Amazon Bedrock model served via inference profile '.$model['model_identifier'].'.',
                        'capabilities' => $capabilities,
                        'active_target_id' => $target->id,
                        'status' => 'active',
                        'created_by' => $admin->id,
                    ],
                );

                ModelAliasTargetVersion::firstOrCreate(
                    [
                        'public_model_alias_id' => $alias->id,
                        'configuration_version' => 1,
                    ],
                    [
                        'upstream_target_id' => $target->id,
                        'effective_at' => now(),
                        'changed_by' => $admin->id,
                    ],
                );

                ModelPricingVersion::firstOrCreate(
                    [
                        'public_model_alias_id' => $alias->id,
                        'effective_at' => $alias->created_at ?? now(),
                    ],
                    [
                        'currency' => 'USD',
                        'input_cost_per_million_tokens' => $model['input_cost'],
                        'output_cost_per_million_tokens' => $model['output_cost'],
                        'created_by' => $admin->id,
                    ],
                );
            }
        });

        $this->command?->info('Bedrock provider, targets, aliases and pricing seeded.');
    }
}
