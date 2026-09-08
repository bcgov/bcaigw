<?php

namespace Database\Factories;

use App\Models\ModelPricingVersion;
use App\Models\PublicModelAlias;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModelPricingVersion>
 */
class ModelPricingVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_model_alias_id' => PublicModelAlias::factory(),
            'effective_at' => now()->subDay(),
            'currency' => 'CAD',
            'input_cost_per_million_tokens' => '3.50000000',
            'output_cost_per_million_tokens' => '10.50000000',
            'cached_input_cost_per_million_tokens' => '1.75000000',
            'created_by' => User::factory(),
        ];
    }
}
