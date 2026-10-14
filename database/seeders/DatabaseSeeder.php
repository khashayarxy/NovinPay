<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * No default users are created: the single admin account is provisioned
     * explicitly via AdminUserSeeder using ADMIN_EMAIL / ADMIN_PASSWORD from .env.
     */
    public function run(): void
    {
        //
    }
}
