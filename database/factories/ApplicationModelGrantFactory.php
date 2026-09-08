<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationModelGrant;
use App\Models\PublicModelAlias;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationModelGrant>
 */
class ApplicationModelGrantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'public_model_alias_id' => PublicModelAlias::factory(),
            'capabilities' => ['chat'],
            'enabled' => true,
            'configuration_version' => 1,
            'granted_by' => User::factory(),
        ];
    }
}
