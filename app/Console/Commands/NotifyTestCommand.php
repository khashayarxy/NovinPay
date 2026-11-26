<?php

namespace App\Console\Commands;

use App\Mail\UnifiedNotificationMail;
use App\Services\Notifications\BaleNotifier;
use App\Services\Notifications\TelegramNotifier;
use App\Support\FaNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotifyTestCommand extends Command
{
    protected $signature = 'novinpay:notify-test {--channel=telegram : Channel to test (telegram, bale, email, or all)}';

    protected $description = 'Send a safe test notification to Telegram, Bale, and/or Admin Email without creating fake orders or database writes.';

    public function handle(TelegramNotifier $telegram, BaleNotifier $bale): int
    {
        $channel = strtolower(trim((string) $this->option('channel')));
        if (! in_array($channel, ['telegram', 'bale', 'email', 'all'], true)) {
            $this->error('Invalid --channel option. Use telegram, bale, email, or all.');

            return self::FAILURE;
        }

        $nowTehran = now('Asia/Tehran')->format('Y-m-d H:i:s');
        $message = "✅ تست اتصال اعلان‌های نوین‌پی\n"
            .'محیط: '.config('app.env')."\n"
            .'زمان (UTC): '.now('UTC')->format('Y-m-d H:i:s');

        $targets = $channel === 'all' ? ['telegram', 'bale', 'email'] : [$channel];
        $allOk = true;

        foreach ($targets as $target) {
            if ($target === 'email') {
                $adminEmails = $this->configuredAdminEmails();
                $emailEnabled = (bool) config('notifications.email.enabled');

                if (! $emailEnabled || $adminEmails === []) {
                    if ($channel === 'all') {
                        continue;
                    }
                    $this->warn('[email] Channel is not enabled or NOTIFICATIONS_ADMIN_EMAILS is empty.');
                    $allOk = false;

                    continue;
                }

                $mailer = (string) config('mail.default', 'smtp');
                if (in_array($mailer, ['log', 'array'], true)) {
                    $this->warn(sprintf('[email] Note: MAIL_MAILER is currently "%s" (non-SMTP).', $mailer));
                }

                $fields = [
                    'شناسه تست' => 'NP-MAIL-TEST-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                    'وضعیت سامانه ایمیل' => 'فعال و آماده ارسال',
                    'محیط اجرا' => (string) config('app.env'),
                    'درایور ایمیل' => strtoupper($mailer),
                    'تعداد گیرندگان مدیر' => FaNumber::digits((string) count($adminEmails)).' آدرس',
                    'زمان ارسال (تهران)' => $nowTehran,
                ];

                $emailOk = true;
                foreach ($adminEmails as $recipient) {
                    try {
                        Mail::to($recipient)->send(new UnifiedNotificationMail(
                            mailSubject: '✅ تست اتصال ایمیل اعلان‌های '.config('business.name', 'نوین‌پی'),
                            heading: 'تست اتصال قالب ایمیل رسمی نوین‌پی',
                            intro: 'این ایمیل آزمایشی جهت اطمینان از صحت تنظیمات SMTP و نمایش صحیح قالب فارسی راست‌به‌چپ (HTML + متن ساده) به ایمیل‌های مدیریتی ارسال شده است.',
                            fields: $fields,
                            actionLabel: 'ورود به پنل مدیریت نوین‌پی',
                            actionUrl: url('/admin'),
                            statusBadge: 'تست اتصال موفق',
                            statusTone: 'success',
                            preheader: 'تست اتصال ایمیل مدیریتی نوین‌پی با موفقیت انجام شد.',
                        ));
                    } catch (Throwable $e) {
                        $this->error(sprintf('[email] Failed sending to %s (%s). Check storage/logs/laravel.log.', $recipient, $e::class));
                        $emailOk = false;
                    }
                }

                if ($emailOk) {
                    $this->info(sprintf('[email] Test notification delivered successfully to %d recipient(s).', count($adminEmails)));
                } else {
                    $allOk = false;
                }

                continue;
            }

            $driver = $target === 'telegram' ? $telegram : $bale;
            if (! $driver->enabled()) {
                $this->warn(sprintf('[%s] Channel is not enabled or missing bot_token/chat_id.', $target));
                $allOk = false;

                continue;
            }

            if ($driver->send($message)) {
                $this->info(sprintf('[%s] Test notification delivered successfully.', $target));
            } else {
                $this->error(sprintf('[%s] Failed to deliver test notification. Check storage/logs/laravel.log.', $target));
                $allOk = false;
            }
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function configuredAdminEmails(): array
    {
        $emails = [];
        foreach ((array) config('notifications.admin.emails', []) as $raw) {
            $clean = trim((string) $raw);
            if ($clean !== '' && filter_var($clean, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $clean;
            }
        }

        return array_values(array_unique($emails));
    }
}
