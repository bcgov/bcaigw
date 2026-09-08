<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\MachineCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MachineCredential>
 */
class MachineCredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => fake()->words(2, true),
            'client_identifier' => 'bcaigw_client_'.bin2hex(random_bytes(16)),
            'secret_hash' => password_hash('test-secret', PASSWORD_ARGON2ID),
            'abilities' => ['gateway.invoke'],
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
