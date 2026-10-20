<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Create a missing initial user from trusted configuration, without granting access.
     *
     * Existing credentials are preserved. Super-admin bootstrap is separate;
     * staff provisioning requires the protected owner. Read config() so
     * provisioning keeps working after `php artisan config:cache`, and are
     * never committed to the repository.
     */
    public function run(): void
    {
        $this->provision(config('admin.email'), config('admin.password'));
    }

    public function provision(mixed $email, mixed $password): void
    {
        if (! is_string($email) || trim($email) === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException(
                'admin.email and admin.password (ADMIN_EMAIL / ADMIN_PASSWORD in .env) must be set before running AdminUserSeeder.'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('ADMIN_PASSWORD must be at least 8 characters long.');
        }

        // Existing accounts and credentials are never overwritten by provisioning.
        // Administrative access is a separate explicit admin:bootstrap operation.
        User::query()->firstOrCreate(
            ['email' => trim($email)],
            ['name' => 'مدیر', 'password' => $password],
        );
    }
}
