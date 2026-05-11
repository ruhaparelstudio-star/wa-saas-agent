<?php

namespace Database\Factories\Modules\Auth\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => UserRole::TENANT_ADMIN,
            'is_active' => true,
            'tenant_id' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function superadmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::SUPERADMIN,
            'tenant_id' => null,
        ]);
    }

    public function tenantAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::TENANT_ADMIN,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
