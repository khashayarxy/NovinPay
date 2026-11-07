<?php

namespace App\Support\Chat;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ChatBridge
{
    private array $contexts = [];

    private array $environment = [];

    public function begin(): void
    {
        $this->contexts = [];
        $GLOBALS['novinpay_chat_contexts'] = [];
        $this->privateDirectory();
        $file = config('support_chat.directory').'/installation.env';
        abort_unless(is_file($file) && ! is_link($file) && is_readable($file), 503);
        abort_unless(filesize($file) !== false && filesize($file) <= 16384, 503);
        $values = Dotenv::parse(file_get_contents($file));
        foreach ($values + ['SUPPORT_CONFIG_FILE' => $file] as $name => $value) {
            abort_unless(is_string($name) && preg_match('/\A(?:SUPPORT_[A-Z0-9_]+|CHAT_DATA_DIR)\z/D', $name), 503);
            $this->environment[$name] = getenv($name);
            putenv($name.'='.$value);
        }
    }

    public function finish(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        $this->environment = [];
        $this->contexts = [];
        unset($GLOBALS['novinpay_chat_contexts']);
    }

    public function session(string $role, array $base): array
    {
        // Operator authority, credentials and sessions are independent of Laravel.
        if ($role === 'operator') {
            return $base;
        }
        if (isset($this->contexts[$role])) {
            return $this->contexts[$role];
        }
        $customer = Auth::guard('customer')->user();
        if ($customer && (! $customer->is_active || request()->session()->get('customer_session_version') !== $customer->session_version)) {
            \support_failure(401, 'identity_changed');
        }
        $bound = DB::table('chat_customer_owners')->where('owner_id', $base['owner_id'])->first();
        if ($bound && (! $customer || (int) $bound->customer_account_id !== (int) $customer->id)) {
            // A website logout/account switch cannot retain access through an old chat cookie.
            if (! session_start()) {
                \support_failure(503, 'session_unavailable');
            }
            $_SESSION = [];
            session_destroy();
            setcookie(\support_session_name('customer'), '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
            \support_failure(401, 'identity_changed');
        }
        if ($customer) {
            // Only possession of this native anonymous session AND current account authentication
            // permits binding. Names/phone inputs never grant conversation ownership.
            DB::table('chat_customer_owners')->insertOrIgnore(['owner_id' => $base['owner_id'], 'customer_account_id' => $customer->id, 'bound_at' => now()->utc()]);
            $bound = DB::table('chat_customer_owners')->where('owner_id', $base['owner_id'])->first();
            if ((int) $bound->customer_account_id !== (int) $customer->id) {
                \support_failure(401, 'identity_changed');
            }
            $base['novinpay_owners'] = DB::table('chat_customer_owners')->where('customer_account_id', $customer->id)->pluck('owner_id')->all();
        }

        return $this->contexts[$role] = $base;
    }

    public function initialize(): void
    {
        abort_unless(PHP_SAPI === 'cli' && app()->environment('staging', 'testing'), 403);
        if (app()->environment('staging')) {
            abort_unless(DB::connection()->getDatabaseName() === 'novinpay_stg38', 403);
        }
        foreach (['flock', 'fsync', 'imagewebp', 'finfo_open'] as $function) {
            abort_unless(function_exists($function), 503, 'Chat runtime prerequisite unavailable.');
        }
        $directory = config('support_chat.directory');
        $this->privateDirectory(true);
        $lockPath = $directory.'/initialization.lock';
        abort_if(is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath)), 503);
        $lock = fopen($lockPath, 'c+b');
        abort_unless(is_resource($lock) && chmod($lockPath, 0600) && flock($lock, LOCK_EX), 503);
        try {
            $file = $directory.'/installation.env';
            if (! file_exists($file)) {
                $values = ['SUPPORT_INSTALLATION_ID' => bin2hex(random_bytes(16)), 'SUPPORT_PUBLIC_ROOT' => public_path(), 'SUPPORT_CORE_DIR' => resource_path('support-chat/support'), 'CHAT_DATA_DIR' => $directory.'/data', 'SUPPORT_RUNTIME_DIR' => $directory.'/runtime', 'SUPPORT_SITE_NAME' => 'نوین‌پی', 'SUPPORT_TIMEZONE' => 'Asia/Tehran', 'SUPPORT_SITE_URL' => config('app.url'), 'SUPPORT_WIDGET_COLOR' => '#0866ff', 'SUPPORT_WIDGET_LOGO' => '/brand/lockups/logo-wordmark-black-on-white.svg', 'SUPPORT_MULTI_OPERATOR_ENABLED' => '1', 'SUPPORT_CUSTOMER_V2_ENABLED' => '1', 'SUPPORT_ATTACHMENTS_ENABLED' => '1', 'SUPPORT_TYPING_ENABLED' => '1', 'SUPPORT_SATISFACTION_ENABLED' => '1', 'SUPPORT_SOURCE_CONTEXT_ENABLED' => '1', 'SUPPORT_SOURCE_PAGES' => '{"/":"صفحه اصلی","/products":"فروشگاه","/repair":"تعمیرات","/contact":"تماس با ما"}', 'SUPPORT_SUGGESTED_QUESTIONS' => '["راهنمای خرید کارتخوان","پیگیری تعمیر دستگاه"]', 'SUPPORT_OPERATOR_THEME_DEFAULT' => 'light', 'SUPPORT_CUSTOMER_THEME_DEFAULT' => 'light', 'SUPPORT_SECRET_RECOVERY_ENABLED' => '0', 'SUPPORT_PHONE_RECOVERY_ENABLED' => '0', 'SUPPORT_CODE_RECOVERY_ENABLED' => '0', 'SUPPORT_NOTIFICATION_QUEUE_ENABLED' => '0', 'SUPPORT_OPERATOR_SMS_ENABLED' => '0', 'SUPPORT_OPERATOR_PUSH_REAL_ENABLED' => '0', 'SUPPORT_OPERATOR_PUSH_ENABLED' => '0', 'SUPPORT_CUSTOMER_PUSH_REAL_ENABLED' => '0', 'SUPPORT_CUSTOMER_PUSH_ENABLED' => '0', 'SUPPORT_EMAIL_RECOVERY_ENABLED' => '0'];
                $values += ['SUPPORT_KNOWLEDGE_ENABLED' => '1', 'SUPPORT_SMS_REAL_DELIVERY_ENABLED' => '0', 'SUPPORT_RECOVERY_EMAIL_ENABLED' => '0', 'SUPPORT_RECOVERY_EMAIL_REAL_DELIVERY_ENABLED' => '0', 'SUPPORT_RECOVERY_ISSUANCE_ENABLED' => '0', 'SUPPORT_RETENTION_ENABLED' => '0'];
                $text = '';
                foreach ($values as $key => $value) {
                    abort_unless(is_string($value) && ! preg_match("/[\\r\\n\\x00']/", $value), 503);
                    $text .= $key.'='."'".$value."'\n";
                }
                $temporary = $file.'.'.bin2hex(random_bytes(12)).'.tmp';
                $handle = fopen($temporary, 'x');
                abort_unless(is_resource($handle), 503);
                try {
                    abort_unless(chmod($temporary, 0600) && fwrite($handle, $text) === strlen($text) && fflush($handle) && fsync($handle), 503);
                } finally {
                    fclose($handle);
                }
                abort_unless(! file_exists($file) && rename($temporary, $file), 503);
            }
            $this->begin();
            try {
                require_once resource_path('support-chat/support/bootstrap.php');
                require_once resource_path('support-chat/support/operators.php');
                require_once resource_path('support-chat/support/satisfaction.php');
                // A new installation must publish its epoch once, before any
                // read-only draft polls. Existing chat data is never rewritten.
                \support_store(static function (array &$state, bool &$changed): void {
                    $changed = ! is_file(CHAT_DATA_DIR.'/chat_conversations.json');
                }, true);
                \support_ops_store(static fn (array &$state) => null, true, true);

                \support_satisfaction_store(static fn (array &$state) => null, true, true);
            } finally {
                $this->finish();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function privateDirectory(bool $create = false): void
    {
        $directory = config('support_chat.directory');
        abort_unless(is_string($directory) && $directory !== '', 503);
        for ($parent = $directory; $parent !== dirname($parent); $parent = dirname($parent)) {
            abort_if(is_link($parent), 503);
        }
        if ($create && ! is_dir($directory)) {
            abort_unless(mkdir($directory, 0700, true) || is_dir($directory), 503);
        }
        $real = realpath($directory);
        $public = realpath(public_path());
        abort_unless($real && $public && ! str_starts_with(strtolower(str_replace('\\', '/', $real)).'/', strtolower(str_replace('\\', '/', $public)).'/') && is_writable($real), 503);
        if (DIRECTORY_SEPARATOR === '/') {
            abort_unless((fileperms($real) & 0077) === 0, 503);
            $file = $real.'/installation.env';
            if (is_file($file)) {
                abort_unless((fileperms($file) & 0077) === 0, 503);
            }
        }
    }
}
