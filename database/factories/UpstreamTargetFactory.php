<?php

namespace Database\Factories;

use App\Enums\ApplicationEnvironment;
use App\Enums\ControlPlaneStatus;
use App\Enums\TargetHealthStatus;
use App\Models\ProviderAccount;
use App\Models\UpstreamTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UpstreamTarget>
 */
class UpstreamTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider_account_id' => ProviderAccount::factory(),
            'name' => fake()->words(3, true),
            'environment' => ApplicationEnvironment::Development,
            'region' => 'canadacentral',
            'base_url' => 'https://models.example.com/v1',
            'provider_model_identifier' => 'deployment-'.fake()->unique()->numerify('####'),
            'capabilities' => ['chat', 'responses', 'streaming'],
            'context_window' => 128_000,
            'max_input_tokens' => 120_000,
            'max_output_tokens' => 8_000,
            'health_status' => TargetHealthStatus::Unknown,
            'status' => ControlPlaneStatus::Active,
            'timeout_seconds' => 30,
            'connection_settings' => ['verify_tls' => true, 'max_connections' => 20],
            'configuration_version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
