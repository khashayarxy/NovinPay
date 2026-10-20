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
    | ADMIN_EMAIL is used only to verify the existing user selected by the
    | explicit admin:bootstrap command. Runtime authorization uses persisted
    | admin_accounts grants, not this email. Never populate it from a request.
    |
    | AdminUserSeeder can create a missing initial user without overwriting
    | an existing account or granting permissions. These credentials are
    | read through config() (not env()) so provisioning keeps working after
    | `php artisan config:cache`, where env() would otherwise return null.
    | Never commit a real .env with actual values.
    |
    */

    'email' => env('ADMIN_EMAIL'),
    'password' => env('ADMIN_PASSWORD'),

];
