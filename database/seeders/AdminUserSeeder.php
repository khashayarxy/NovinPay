<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the single admin account from ADMIN_EMAIL / ADMIN_PASSWORD.
     *
     * There is no public registration; this is the only way to provision
     * an admin user. Credentials are read from the environment and are
     * never committed to the repository.
     */
    public function run(): void
    {
        $this->provision(env('ADMIN_EMAIL'), env('ADMIN_PASSWORD'));
    }

    public function provision(mixed $email, mixed $password): void
    {
        if (! is_string($email) || trim($email) === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException(
                'ADMIN_EMAIL and ADMIN_PASSWORD must be set in .env before running AdminUserSeeder.'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('ADMIN_PASSWORD must be at least 8 characters long.');
        }

        User::query()->updateOrCreate(
            ['email' => trim($email)],
            ['name' => 'مدیر', 'password' => $password],
        );
    }
}
