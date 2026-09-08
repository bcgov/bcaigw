<?php

namespace Database\Factories;

use App\Enums\ControlPlaneStatus;
use App\Models\PublicModelAlias;
use App\Models\UpstreamTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicModelAlias>
 */
class PublicModelAliasFactory extends Factory
{
    public function definition(): array
    {
        return [
            'model_id' => 'bcgov/'.fake()->unique()->slug(2),
            'display_name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'capabilities' => ['chat'],
            'active_target_id' => UpstreamTarget::factory(),
            'status' => ControlPlaneStatus::Active,
            'configuration_version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
