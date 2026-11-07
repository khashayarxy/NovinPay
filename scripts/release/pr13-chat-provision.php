<?php

declare(strict_types=1);
use App\Support\Chat\ChatBridge;
use Illuminate\Foundation\Application;

/** Fixed-scope production provisioning; no HTTP entry point or reusable bypass. */
function pr13ProvisionChat(Application $app, string $journal): void
{
    $need = 'NovinPay\\Release\\Pr13\\need';
    $root = '/home/novinpay/novinpay';
    $need(PHP_SAPI === 'cli' && $app->basePath() === $root && $app->environment('production')
        && $journal === '/home/novinpay/novinpay-release-pipeline-v1/pipeline-pr13-activation-r4'
        && is_file($journal.'/ddl.complete.json') && is_file($root.'/storage/framework/down'), 'chat_provision_release_scope');
    foreach (['flock', 'fsync', 'imagewebp', 'finfo_open'] as $function) {
        $need(function_exists($function), 'chat_runtime_prerequisite');
    }
    $directory = $root.'/storage/app/private/support-chat';
    for ($path = $directory; $path !== dirname($path); $path = dirname($path)) {
        $need(! is_link($path), 'chat_provision_path_link');
    }
    if (! is_dir($directory)) {
        $need(mkdir($directory, 0700, true), 'chat_provision_directory');
    }
    $need(fileowner($directory) === 1321 && (fileperms($directory) & 0777) === 0700, 'chat_provision_permissions');
    $file = $directory.'/installation.env';
    if (! file_exists($file)) {
        // Exact reviewed ChatBridge::initialize defaults, with all external
        // notification/recovery delivery disabled. Never copy fixture credentials.
        $values = ['SUPPORT_INSTALLATION_ID' => bin2hex(random_bytes(16)), 'SUPPORT_PUBLIC_ROOT' => $root.'/public', 'SUPPORT_CORE_DIR' => $root.'/resources/support-chat/support',
            'CHAT_DATA_DIR' => $directory.'/data', 'SUPPORT_RUNTIME_DIR' => $directory.'/runtime', 'SUPPORT_SITE_NAME' => 'نوین‌پی', 'SUPPORT_TIMEZONE' => 'Asia/Tehran',
            'SUPPORT_SITE_URL' => 'https://novinpaypos.com', 'SUPPORT_WIDGET_COLOR' => '#0866ff', 'SUPPORT_WIDGET_LOGO' => '/brand/lockups/logo-wordmark-black-on-white.svg',
            'SUPPORT_SOURCE_PAGES' => '{"/":"صفحه اصلی","/products":"فروشگاه","/repair":"تعمیرات","/contact":"تماس با ما"}',
            'SUPPORT_SUGGESTED_QUESTIONS' => '["راهنمای خرید کارتخوان","پیگیری تعمیر دستگاه"]', 'SUPPORT_OPERATOR_THEME_DEFAULT' => 'light', 'SUPPORT_CUSTOMER_THEME_DEFAULT' => 'light'];
        foreach (['MULTI_OPERATOR', 'CUSTOMER_V2', 'ATTACHMENTS', 'TYPING', 'SATISFACTION', 'SOURCE_CONTEXT', 'KNOWLEDGE'] as $key) {
            $values['SUPPORT_'.$key.'_ENABLED'] = '1';
        }
        foreach (['SECRET_RECOVERY', 'PHONE_RECOVERY', 'CODE_RECOVERY', 'NOTIFICATION_QUEUE', 'OPERATOR_SMS', 'OPERATOR_PUSH_REAL', 'OPERATOR_PUSH', 'CUSTOMER_PUSH_REAL', 'CUSTOMER_PUSH',
            'EMAIL_RECOVERY', 'SMS_REAL_DELIVERY', 'RECOVERY_EMAIL', 'RECOVERY_EMAIL_REAL_DELIVERY', 'RECOVERY_ISSUANCE', 'RETENTION'] as $key) {
            $values['SUPPORT_'.$key.'_ENABLED'] = '0';
        }
        $text = '';
        foreach ($values as $key => $value) {
            $need(! preg_match('/[\r\n\x00\x27]/', $value), 'chat_provision_value');
            $text .= $key."='".$value."'\n";
        }
        $handle = fopen($file, 'xb');
        try {
            $need(is_resource($handle) && chmod($file, 0600) && fwrite($handle, $text) === strlen($text) && fflush($handle) && fsync($handle), 'chat_provision_file');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
    $values = Dotenv\Dotenv::parse(NovinPay\Release\privateRead($file));
    $need(($values['SUPPORT_PUBLIC_ROOT'] ?? '') === $root.'/public' && ($values['CHAT_DATA_DIR'] ?? '') === $directory.'/data'
        && ($values['SUPPORT_CORE_DIR'] ?? '') === $root.'/resources/support-chat/support' && ($values['SUPPORT_SITE_URL'] ?? '') === 'https://novinpaypos.com', 'chat_existing_configuration_identity');
    foreach ($values as $key => $value) {
        if (str_contains($key, 'REAL_') || str_contains($key, '_SMS_') || str_contains($key, 'RECOVERY_') || str_contains($key, '_PUSH_') || $key === 'SUPPORT_NOTIFICATION_QUEUE_ENABLED' || $key === 'SUPPORT_RETENTION_ENABLED') {
            $need($value === '0', 'chat_external_delivery_disabled');
        }
    }
    $bridge = $app->make(ChatBridge::class);
    $bridge->begin();
    try {
        require_once $root.'/resources/support-chat/support/bootstrap.php';
        require_once $root.'/resources/support-chat/support/operators.php';
        require_once $root.'/resources/support-chat/support/satisfaction.php';
        support_store(static function (array &$state, bool &$changed): void {
            $changed = ! is_file(CHAT_DATA_DIR.'/chat_conversations.json');
        }, true);
        support_ops_store(static fn (array &$state) => null, true, true);
        $bridge->syncOperators();
        support_satisfaction_store(static fn (array &$state) => null, true, true);
    } finally {
        $bridge->finish();
    }
}
