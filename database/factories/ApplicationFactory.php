<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\DataClassification;
use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'api_directory_client_id' => fake()->unique()->uuid(),
            'name' => fake()->company().' AI Service',
            'ministry_organization' => fake()->company(),
            'purpose_use_case' => fake()->paragraph(),
            'primary_contact_name' => fake()->name(),
            'primary_contact_email' => fake()->safeEmail(),
            'technical_contact_name' => fake()->name(),
            'technical_contact_email' => fake()->safeEmail(),
            'environments' => ['development'],
            'expected_requests_per_minute' => 60,
            'expected_tokens_per_month' => 1_000_000,
            'data_classification' => DataClassification::Internal,
            'requested_models' => ['azure-openai-gpt-4o'],
            'requested_capabilities' => ['chat'],
            'prompt_response_retention_enabled' => true,
            'configuration_ready' => false,
            'budget_currency' => 'CAD',
            'status' => ApplicationStatus::Draft,
            'status_version' => 0,
            'created_by' => User::factory(),
        ];
    }
}
