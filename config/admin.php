<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin Login Throttle
    |--------------------------------------------------------------------------
    |
    | Ceiling of failed login attempts per IP address across all email
    | addresses, checked before credential validation so malformed-input
    | floods are bounded too. The per-email ceiling is a hard-coded
    | constant in Admin\LoginController (5 attempts / 60 seconds).
    |
    */

    'login_ip_max_attempts' => (int) env('ADMIN_LOGIN_IP_MAX_ATTEMPTS', 30),

    /*
    |--------------------------------------------------------------------------
    | Admin Provisioning Credentials
    |--------------------------------------------------------------------------
    |
    | The email also identifies the ONLY persisted account allowed by the
    | access-admin gate. Empty/missing email denies administrator access.
    | Keep it aligned with the existing administrator; no seeding is needed
    | when deploying the gate. Never populate it from a request.
    |
    | Used by AdminUserSeeder to create the single admin account. These are
    | read through config() (not env()) so provisioning keeps working after
    | `php artisan config:cache`, where env() would otherwise return null.
    | Never commit a real .env with actual values.
    |
    */

    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),

];
