<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Notifications\BaleNotifier;
use App\Services\Notifications\UnifiedNotifier;
use App\Services\Observability\TelegramTopicNotifier;
use App\Support\AdminHistory;
use App\Support\FaNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

final class ActivationDocumentsService
{
    private const MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly TelegramTopicNotifier $topics,
        private readonly UnifiedNotifier $notifier,
        private readonly BaleNotifier $bale,
    ) {}

    /**
     * @return array<string, array{label: string, hint: string, zip_prefix: string, required: bool}>
     */
    public function configuredSlots(): array
    {
        $raw = (array) config('novinpay.activation_document_slots', []);
        $slots = [];
        $index = 1;
        foreach ($raw as $key => $meta) {
            if (! is_string($key) || ! preg_match('/\A[a-z0-9_]{1,40}\z/', $key) || ! is_array($meta)) {
                continue;
            }
            $prefix = preg_replace('/[^a-zA-Z0-9\-]/', '', (string) ($meta['zip_prefix'] ?? sprintf('%02d-%s', $index, str_replace('_', '-', $key)))) ?: sprintf('%02d-doc', $index);
            $slots[$key] = [
                'label' => trim((string) ($meta['label'] ?? $key)),
                'hint' => trim((string) ($meta['hint'] ?? '')),
                'zip_prefix' => $prefix,
                'required' => (bool) ($meta['required'] ?? true),
            ];
            $index++;
        }

        return $slots;
    }

    /**
     * Order is eligible only when settled through the online payment gateway (never card-to-card / manual).
     */
    public function isEligibleForDocuments(SalesOrder $order, ?FinancialDocument $document = null): bool
    {
        if ($order->status === 'cancelled') {
            return false;
        }

        $document ??= FinancialDocument::query()->where('sales_order_id', $order->id)->first();
        if (! $document || $document->charged_minor <= 0 || $document->paid_minor < $document->charged_minor) {
            return false;
        }

        $hasSettledGatewayAttempt = PaymentAttempt::query()
            ->where('financial_document_id', $document->id)
            ->where('state', 'settled')
            ->exists();
        if ($hasSettledGatewayAttempt) {
            return true;
        }

        return PaymentTransaction::query()
            ->where('financial_document_id', $document->id)
            ->whereNotNull('payment_attempt_id')
            ->where('allocated_minor', '>', 0)
            ->exists();
    }

    public function canCustomerUpload(SalesOrder $order, ?FinancialDocument $document = null): bool
    {
        if (! $this->isEligibleForDocuments($order, $document)) {
            return false;
        }

        $status = (string) ($order->documents_status ?: 'pending_submission');

        return in_array($status, ['pending_submission', 'needs_revision'], true);
    }

    /**
     * Bundle uploaded files into an AES-256 encrypted ZIP, deliver to Telegram, and immediately purge host files on confirmation.
     *
     * @param  array<string, UploadedFile>  $slotFiles
     * @param  list<UploadedFile>  $extraFiles
     * @return array{telegram_delivered: bool}
     */
    public function submitCustomerDocuments(SalesOrder $order, array $slotFiles, array $extraFiles = []): array
    {
        $password = (string) config('novinpay.docs_zip_password', '');
        if (strlen(trim($password)) < 8) {
            Log::error('Activation document submission blocked because DOCS_ZIP_PASSWORD is missing or shorter than 8 characters');
            throw ValidationException::withMessages([
                'documents' => 'سامانه دریافت رمزنگاری‌شده مدارک موقتاً آماده نیست. لطفاً با پشتیبانی تماس بگیرید.',
            ]);
        }

        $order->loadMissing(['customer', 'lines']);
        $shortCode = strtoupper(substr((string) $order->public_id, 0, 8));
        $customerName = trim((string) ($order->customer?->name ?? 'مشتری'));
        $customerPhone = trim((string) ($order->customer?->contact_phone ?? '—'));
        $deviceSummary = $order->lines->pluck('description')->filter()->unique()->implode('، ') ?: 'کارتخوان نوین‌پی';

        $basePrivateDir = storage_path('app/private/activation-docs/'.$order->public_id);
        $batchDir = $basePrivateDir.'/batch-'.Str::uuid();
        File::ensureDirectoryExists($batchDir, 0700, true);
        @chmod($basePrivateDir, 0700);
        @chmod($batchDir, 0700);

        $slots = $this->configuredSlots();
        $stagedEntries = [];

        try {
            foreach ($slots as $slotKey => $slotMeta) {
                $uploaded = $slotFiles[$slotKey] ?? null;
                if (! $uploaded instanceof UploadedFile) {
                    continue;
                }
                $ext = $this->safeExtension($uploaded);
                $entryName = $slotMeta['zip_prefix'].'.'.$ext;
                $destPath = $batchDir.'/'.$entryName;
                if (! copy($uploaded->getRealPath(), $destPath)) {
                    throw new RuntimeException('Failed staging activation document file.');
                }
                @chmod($destPath, 0600);
                $stagedEntries[$entryName] = $destPath;
            }

            $extraIndex = 1;
            foreach ($extraFiles as $extra) {
                if (! $extra instanceof UploadedFile) {
                    continue;
                }
                $ext = $this->safeExtension($extra);
                $entryName = sprintf('06-takmili-%d.%s', $extraIndex, $ext);
                $destPath = $batchDir.'/'.$entryName;
                if (! copy($extra->getRealPath(), $destPath)) {
                    throw new RuntimeException('Failed staging extra activation document file.');
                }
                @chmod($destPath, 0600);
                $stagedEntries[$entryName] = $destPath;
                $extraIndex++;
            }

            if ($stagedEntries === []) {
                throw ValidationException::withMessages([
                    'documents' => 'هیچ فایل معتبری برای ارسال مدارک انتخاب نشده است.',
                ]);
            }

            $infoLines = [
                'نوین‌پی — پرونده مدارک فعال‌سازی و سامانه مالیاتی',
                '==================================================',
                'کد سفارش: #'.$shortCode,
                'نام مشتری: '.$customerName,
                'شماره تماس: '.$customerPhone,
                'مدل دستگاه / اقلام: '.$deviceSummary,
                'تعداد فایل‌های پیوست: '.count($stagedEntries),
                'زمان ثبت (تهران): '.now('Asia/Tehran')->format('Y-m-d H:i:s'),
            ];
            $infoPath = $batchDir.'/info.txt';
            file_put_contents($infoPath, implode("\n", $infoLines)."\n");
            @chmod($infoPath, 0600);
            $stagedEntries['info.txt'] = $infoPath;

            $zipFileName = 'docs-'.strtolower($shortCode).'-'.now('UTC')->format('YmdHis').'.zip';
            $zipPath = $basePrivateDir.'/'.$zipFileName;
            $this->buildAes256Zip($zipPath, $stagedEntries, $password);

            // Always remove plaintext staged copies immediately now that the AES-256 ZIP is sealed.
            File::deleteDirectory($batchDir);

            $adminUrl = route('admin.orders.show', $order);
            $caption = implode("\n", [
                '🗂️ مدارک فعال‌سازی و مالیاتی سفارش جدید (AES-256)',
                '• کد سفارش: #'.$shortCode,
                '• نام مشتری: '.$customerName,
                '• شماره تماس: '.FaNumber::digits($customerPhone),
                '• مدل دستگاه: '.$deviceSummary,
                '• زمان ارسال: '.FaNumber::digits(now('Asia/Tehran')->format('Y/m/d H:i')),
            ]);

            $previousChatId = $order->telegram_chat_id;
            $previousMsgId = $order->telegram_message_id;
            $wasUnpurged = $order->documents_purged_at === null && $previousChatId && $previousMsgId;

            $result = $this->topics->sendDocumentWithResult(
                topic: 'documents',
                filePath: $zipPath,
                caption: $caption,
                downloadName: "novinpay-docs-{$shortCode}.zip",
                inlineKeyboard: [[['text' => 'مشاهده سفارش در پنل مدیریت', 'url' => $adminUrl]]],
            );

            if ($result['ok'] && $result['message_id'] !== null) {
                // Telegram confirmed delivery -> delete temporary encrypted ZIP & directory immediately (Zero Host Retention).
                @unlink($zipPath);
                File::deleteDirectory($basePrivateDir);

                if ($wasUnpurged) {
                    $this->topics->deleteMessage((string) $previousChatId, (int) $previousMsgId);
                }

                $order->forceFill([
                    'documents_status' => 'pending_review',
                    'documents_revision_reason' => null,
                    'documents_submitted_at' => now(),
                    'documents_purged_at' => null,
                    'telegram_chat_id' => $result['chat_id'],
                    'telegram_message_id' => $result['message_id'],
                    'documents_staging_dir' => null,
                ])->save();

                return ['telegram_delivered' => true];
            }

            // Telegram delivery failed -> retain ONLY the AES-256 encrypted ZIP safely in storage/app/private and alert admins.
            $retainedZip = $basePrivateDir.'/pending-telegram-'.strtolower($shortCode).'.zip';
            if ($zipPath !== $retainedZip) {
                @rename($zipPath, $retainedZip);
                @chmod($retainedZip, 0600);
            }

            $order->forceFill([
                'documents_status' => 'pending_review',
                'documents_revision_reason' => null,
                'documents_submitted_at' => now(),
                'documents_purged_at' => null,
                'documents_staging_dir' => 'activation-docs/'.$order->public_id,
            ])->save();

            $this->alertRetainedSubmission($order, $shortCode, $customerName, $deviceSummary, $adminUrl);

            return ['telegram_delivered' => false];
        } catch (ValidationException $e) {
            File::deleteDirectory($batchDir);
            if (is_dir($basePrivateDir) && count(File::allFiles($basePrivateDir)) === 0) {
                File::deleteDirectory($basePrivateDir);
            }
            throw $e;
        } catch (Throwable $e) {
            File::deleteDirectory($batchDir);
            Log::error('Activation documents packaging failed', [
                'order' => $shortCode,
                'exception' => $e::class,
            ]);
            throw ValidationException::withMessages([
                'documents' => 'خطایی در رمزنگاری و ثبت مدارک رخ داد. لطفاً دوباره تلاش کنید.',
            ]);
        }
    }

    /**
     * Retry delivering a retained encrypted ZIP from storage/app/private to Telegram and delete on confirmation.
     */
    public function retryRetainedTelegramDelivery(SalesOrder $order): bool
    {
        $basePrivateDir = storage_path('app/private/activation-docs/'.$order->public_id);
        if (! is_dir($basePrivateDir)) {
            return false;
        }

        $zips = glob($basePrivateDir.'/*.zip') ?: [];
        if ($zips === []) {
            return false;
        }
        $zipPath = $zips[0];

        $order->loadMissing(['customer', 'lines']);
        $shortCode = strtoupper(substr((string) $order->public_id, 0, 8));
        $customerName = trim((string) ($order->customer?->name ?? 'مشتری'));
        $customerPhone = trim((string) ($order->customer?->contact_phone ?? '—'));
        $deviceSummary = $order->lines->pluck('description')->filter()->unique()->implode('، ') ?: 'کارتخوان نوین‌پی';
        $adminUrl = route('admin.orders.show', $order);

        $caption = implode("\n", [
            '🗂️ مدارک فعال‌سازی و مالیاتی سفارش (ارسال مجدد خودکار · AES-256)',
            '• کد سفارش: #'.$shortCode,
            '• نام مشتری: '.$customerName,
            '• شماره تماس: '.FaNumber::digits($customerPhone),
            '• مدل دستگاه: '.$deviceSummary,
        ]);

        $result = $this->topics->sendDocumentWithResult(
            topic: 'documents',
            filePath: $zipPath,
            caption: $caption,
            downloadName: "novinpay-docs-{$shortCode}.zip",
            inlineKeyboard: [[['text' => 'مشاهده سفارش در پنل مدیریت', 'url' => $adminUrl]]],
        );

        if (! $result['ok'] || $result['message_id'] === null) {
            return false;
        }

        File::deleteDirectory($basePrivateDir);
        $order->forceFill([
            'telegram_chat_id' => $result['chat_id'],
            'telegram_message_id' => $result['message_id'],
            'documents_staging_dir' => null,
        ])->save();

        return true;
    }

    public function approve(SalesOrder $order, User $actor): void
    {
        $order->forceFill([
            'documents_status' => 'approved',
            'documents_revision_reason' => null,
            'documents_reviewed_at' => now(),
            'documents_reviewed_by' => $actor->id,
        ])->save();

        AdminHistory::record(AdminAction::OrderChanged, 'sales_order', $order->id, $actor->id);

        $this->notifier->orderDocumentsApproved($order);
    }

    public function requestRevision(SalesOrder $order, User $actor, string $reason): void
    {
        $cleanReason = trim($reason);
        $order->forceFill([
            'documents_status' => 'needs_revision',
            'documents_revision_reason' => $cleanReason,
            'documents_reviewed_at' => now(),
            'documents_reviewed_by' => $actor->id,
        ])->save();

        AdminHistory::record(AdminAction::OrderChanged, 'sales_order', $order->id, $actor->id);

        $this->notifier->orderDocumentsRevision($order, $cleanReason);
    }

    public function purgeOrderDocuments(SalesOrder $order, ?User $actor = null): bool
    {
        $deletedOnTelegram = true;
        if ($order->telegram_chat_id && $order->telegram_message_id) {
            $deletedOnTelegram = $this->topics->deleteMessage(
                (string) $order->telegram_chat_id,
                (int) $order->telegram_message_id,
            );
        }

        $basePrivateDir = storage_path('app/private/activation-docs/'.$order->public_id);
        if (is_dir($basePrivateDir)) {
            File::deleteDirectory($basePrivateDir);
        }

        $order->forceFill([
            'documents_purged_at' => now(),
            'telegram_message_id' => null,
            'documents_staging_dir' => null,
        ])->save();

        if ($actor !== null) {
            AdminHistory::record(AdminAction::OrderChanged, 'sales_order', $order->id, $actor->id);
        }

        return $deletedOnTelegram;
    }

    /**
     * Automatically purge Telegram messages & private files for delivered (>= retention days), cancelled, or >30d abandoned orders.
     */
    public function purgeExpiredDocuments(): int
    {
        $retentionDays = max(1, (int) config('novinpay.docs_retention_days', 7));
        $abandonedDays = max(1, (int) config('novinpay.docs_abandoned_days', 30));

        $deliveredCutoff = now()->subDays($retentionDays);
        $abandonedCutoff = now()->subDays($abandonedDays);

        $candidates = SalesOrder::query()
            ->whereNull('documents_purged_at')
            ->where(function ($q): void {
                $q->whereNotNull('telegram_message_id')
                    ->orWhereNotNull('documents_staging_dir');
            })
            ->where(function ($q) use ($deliveredCutoff, $abandonedCutoff): void {
                $q->where('status', 'cancelled')
                    ->orWhere(function ($delivered) use ($deliveredCutoff): void {
                        $delivered->where('status', 'fulfilled')
                            ->where(function ($ts) use ($deliveredCutoff): void {
                                $ts->where('fulfilled_at', '<=', $deliveredCutoff)
                                    ->orWhere(function ($fallback) use ($deliveredCutoff): void {
                                        $fallback->whereNull('fulfilled_at')
                                            ->where('updated_at', '<=', $deliveredCutoff);
                                    });
                            });
                    })
                    ->orWhere(function ($abandoned) use ($abandonedCutoff): void {
                        $abandoned->where('status', '!=', 'fulfilled')
                            ->where('created_at', '<=', $abandonedCutoff);
                    });
            })
            ->get();

        $purgedCount = 0;
        foreach ($candidates as $order) {
            $this->purgeOrderDocuments($order);
            $purgedCount++;
        }

        return $purgedCount;
    }

    /**
     * @param  array<string, string>  $stagedEntries
     */
    private function buildAes256Zip(string $zipPath, array $stagedEntries, string $password): void
    {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open ZipArchive for writing.');
        }

        if (! $zip->setPassword($password)) {
            $zip->close();
            @unlink($zipPath);
            throw new RuntimeException('Unable to set ZipArchive password.');
        }

        foreach ($stagedEntries as $entryName => $sourcePath) {
            if (! $zip->addFile($sourcePath, $entryName)) {
                $zip->close();
                @unlink($zipPath);
                throw new RuntimeException("Unable to add [{$entryName}] to ZipArchive.");
            }
            if (! $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256)) {
                $zip->close();
                @unlink($zipPath);
                throw new RuntimeException("Unable to set AES-256 encryption on [{$entryName}].");
            }
        }

        if (! $zip->close() || ! is_file($zipPath) || filesize($zipPath) <= 0) {
            @unlink($zipPath);
            throw new RuntimeException('Failed finalizing AES-256 encrypted ZIP archive.');
        }

        @chmod($zipPath, 0600);
    }

    private function safeExtension(UploadedFile $file): string
    {
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        if (isset(self::MIME_TO_EXT[$mime])) {
            return self::MIME_TO_EXT[$mime];
        }

        $clientExt = strtolower($file->getClientOriginalExtension());

        return match ($clientExt) {
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            'pdf' => 'pdf',
            default => throw ValidationException::withMessages([
                'documents' => 'فرمت فایل مجاز نیست. فقط تصاویر JPG، PNG، WebP یا فایل PDF قابل قبول است.',
            ]),
        };
    }

    private function alertRetainedSubmission(
        SalesOrder $order,
        string $shortCode,
        string $customerName,
        string $deviceSummary,
        string $adminUrl,
    ): void {
        try {
            $fields = [
                'کد سفارش' => '#'.$shortCode,
                'مشتری' => $customerName,
                'دستگاه' => $deviceSummary,
                'وضعیت فایل' => 'ذخیره رمزنگاری‌شده AES-256 در فضای خصوصی سرور (در انتظار ارسال به تلگرام)',
            ];
            $this->notifier->criticalOperationalAlert(
                category: 'documents',
                subject: "⚠️ خطا در ارسال مدارک سفارش #{$shortCode} به تلگرام (ذخیره‌شده در سرور)",
                heading: 'مدارک فعال‌سازی مشتری در سرور به‌صورت رمزنگاری‌شده نگهداری شد',
                intro: 'ارسال فایل ZIP رمزنگاری‌شده به تلگرام موقتاً ناموفق بود. فایل مشتری بدون از دست رفتن در مسیر خصوصی سرور نگهداری شده و از پنل سفارش قابل ارسال مجدد است.',
                fields: $fields,
                dedupeKey: 'docs_tg_fail:'.$order->public_id.':'.now()->format('YmdHi'),
                ttlMinutes: 10,
                actionLabel: 'مشاهده سفارش و ارسال مجدد',
                actionUrl: $adminUrl,
                statusBadge: 'نگهداری اضطراری مدارک',
                statusTone: 'warning',
            );
            $this->bale->send(
                "⚠️ ارسال مدارک سفارش #{$shortCode} به تلگرام ناموفق بود؛ فایل رمزنگاری‌شده در فضای امن سرور نگهداری شد.",
                'docs_tg_fail:'.$order->public_id,
                60,
                $adminUrl,
                'مشاهده سفارش',
            );
        } catch (Throwable) {
        }
    }
}
