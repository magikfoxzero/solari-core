<?php

namespace NewSolari\Core\Database\Factories;

use NewSolari\Core\Identity\Models\IdentityUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\NewSolari\Core\Identity\Models\IdentityUser>
 */
class IdentityUserFactory extends Factory
{
    protected $model = IdentityUser::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_id' => (string) Str::uuid(),
            'partition_id' => 'test-partition-00000000-0000-0000-0000-000000000000',
            'username' => $this->faker->userName,
            'email' => $this->faker->unique()->safeEmail,
            'password_hash' => Hash::make('password'),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the user is a system user.
     * Uses afterCreating to call setSystemUser() since is_system_user
     * is intentionally NOT mass-assignable for defense-in-depth.
     */
    public function systemUser(): static
    {
        return $this->afterCreating(function (IdentityUser $user) {
            $user->setSystemUser(true);
        });
    }

    /**
     * Indicate that the user is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
