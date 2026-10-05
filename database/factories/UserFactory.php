<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'guid' => Str::uuid()->getHex()->toString(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $firstName.' '.$lastName,
            'disabled' => false,
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'idir_username' => strtoupper(Str::random(8)),
            'idir_user_guid' => Str::uuid()->getHex()->toString(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'disabled' => true,
        ]);
    }
}
