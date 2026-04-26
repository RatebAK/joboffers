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
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= $this->hashPassword('password'),
            'remember_token' => Str::random(10),
            'roles' => ['employee'], // Default role
        ];
    }

    /**
     * Hash password with fallback for testing environments
     */
    private function hashPassword(string $password): string
    {
        try {
            return Hash::make($password);
        } catch (\Exception $e) {
            // Fallback for testing environments where bcrypt is not available
            return hash('sha256', $password . 'salt');
        }
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Create a user with admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'roles' => ['admin'],
        ]);
    }

    /**
     * Create a user with employer role.
     */
    public function employer(): static
    {
        return $this->state(fn (array $attributes) => [
            'roles' => ['employer'],
        ]);
    }

    /**
     * Create a user with employee role.
     */
    public function employee(): static
    {
        return $this->state(fn (array $attributes) => [
            'roles' => ['employee'],
        ]);
    }

    /**
     * Create a user with multiple roles.
     */
    public function withRoles(array $roles): static
    {
        return $this->state(fn (array $attributes) => [
            'roles' => $roles,
        ]);
    }

    /**
     * Create a user with both employer and employee roles.
     */
    public function multiRole(): static
    {
        return $this->state(fn (array $attributes) => [
            'roles' => ['employer', 'employee'],
        ]);
    }
}
