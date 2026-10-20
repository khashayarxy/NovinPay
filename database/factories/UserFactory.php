<?php

namespace Database\Factories;

use App\Models\AdminAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
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
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
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

    public function superAdmin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $account = new AdminAccount;
            $account->user_id = $user->id;
            $account->role = 'super_admin';
            $account->owner_slot = 1;
            $account->is_active = true;
            $account->permissions = [];
            $account->must_change_password = false;
            $account->save();
        });
    }

    public function staff(array $permissions = [], bool $active = true, bool $mustChangePassword = false): static
    {
        return $this->afterCreating(function (User $user) use ($permissions, $active, $mustChangePassword): void {
            $account = new AdminAccount;
            $account->user_id = $user->id;
            $account->role = 'staff';
            $account->is_active = $active;
            $account->permissions = $permissions;
            $account->must_change_password = $mustChangePassword;
            $account->save();
        });
    }
}
