<?php

namespace Database\Factories;

use App\Enums\ApplicationEnvironment;
use App\Enums\ControlPlaneStatus;
use App\Enums\ProviderType;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderAccount>
 */
class ProviderAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' model provider',
            'type' => ProviderType::AzureAiFoundry,
            'environment' => ApplicationEnvironment::Development,
            'region' => 'canadacentral',
            'secret_reference' => 'platform/model-provider',
            'configuration' => ['tenant_id' => fake()->uuid()],
            'status' => ControlPlaneStatus::Active,
            'configuration_version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
