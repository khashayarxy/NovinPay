<?php

namespace App\Services\Notifications;

use App\Enums\RepairStage;
use App\Mail\UnifiedNotificationMail;
use App\Models\Consultation;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\PaymentAttempt;
use App\Models\PaymentTransaction;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Services\IpPanel;
use App\Support\FaNumber;
use App\Support\RepairPaymentCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class UnifiedNotifier
{
    public function __construct(
        private readonly IpPanel $ipPanel,
        private readonly BaleNotifier $bale,
        private readonly TelegramNotifier $telegram,
        private readonly PatternSms $patternSms,
    ) {}

    public function orderPlaced(SalesOrder $order, ?string $customerEmail = null): void
    {
        $this->safe(function () use ($order, $customerEmail): void {
            $order->loadMissing(['customer', 'lines']);
            $code = strtoupper(substr((string) $order->public_id, 0, 8));
            $customerName = (string) ($order->customer?->name ?? 'مشتری');
            $customerPhone = $this->normalizePhone($order->customer?->contact_phone);
            $totalToman = FaNumber::digits(number_format((int) round((float) $order->total))).' تومان';
            $itemCount = FaNumber::digits($order->lines->sum('quantity'));
            $adminUrl = route('admin.orders.show', $order);

            $adminFields = [
                'شماره سفارش' => $code,
                'نام مشتری' => $customerName,
                'تعداد اقلام' => $itemCount,
                'مبلغ کل' => $totalToman,
            ];

            $this->sendAdminSms('admin_order_placed', ['code' => $code, 'name' => $customerName, 'total' => $totalToman]);
            $this->sendAdminEmail('سفارش جدید #'.$code, 'ثبت سفارش خرید جدید', 'یک سفارش جدید در فروشگاه ثبت شد.', $adminFields, 'مشاهده سفارش در پنل', $adminUrl);

            if ($customerPhone !== null && $this->patternSms->configurationIssue() !== null) {
                $this->sendSms($customerPhone, 'customer_order_placed', ['code' => $code, 'total' => $totalToman]);
            }
            $this->sendCustomerEmail(
                $customerEmail,
                'ثبت سفارش شما در '.config('business.name', 'نوین‌پی').' (#'.$code.')',
                'سفارش شما با موفقیت ثبت شد',
                'سفارش شما دریافت شد و پس از بررسی موجودی و تایید، مراحل ارسال اطلاع‌رسانی خواهد شد.',
                ['شماره سفارش' => $code, 'مبلغ کل' => $totalToman],
                'پیگیری در حساب کاربری',
                url('/account'),
            );
        }, 'order_placed');
    }

    public function orderShipped(SalesOrder $order, ?string $tracking = null, ?string $customerEmail = null): void
    {
        $this->safe(function () use ($order, $tracking, $customerEmail): void {
            $order->loadMissing('customer');
            $code = strtoupper(substr((string) $order->public_id, 0, 8));
            $customerPhone = $this->normalizePhone($order->customer?->contact_phone);
            $trackingText = trim((string) $tracking) !== '' ? trim((string) $tracking) : 'ثبت شده در حساب کاربری';

            if (trim((string) $tracking) !== '') {
                $this->orderPattern($order, 'order_shipped', ['tracking_code' => trim($tracking)]);
            }
            if ($customerPhone !== null && $this->patternSms->configurationIssue() !== null) {
                $this->sendSms($customerPhone, 'customer_order_shipped', ['code' => $code, 'tracking' => $trackingText]);
            }
            $this->sendCustomerEmail(
                $customerEmail,
                'سفارش شما ارسال شد (#'.$code.')',
                'سفارش شما تحویل شرکت حمل شد',
                'سفارش شما ارسال شده است و می‌توانید وضعیت آن را با کد پیگیری زیر دنبال کنید.',
                ['شماره سفارش' => $code, 'کد پیگیری ارسال' => $trackingText],
                'مشاهده سفارش در حساب کاربری',
                url('/account'),
            );
        }, 'order_shipped');
    }

    /** @param array<string, string> $variables */
    public function orderPattern(SalesOrder $order, string $event, array $variables = []): void
    {
        $this->safe(function () use ($order, $event, $variables): void {
            $order->loadMissing('customer');
            if ($event === 'order_confirmed') {
                $order->loadMissing('lines');
                $variables['name'] = (string) ($order->customer?->name ?: 'مشتری');
                $products = $order->lines->pluck('description')->filter()->unique()->implode('، ');
                $variables['product'] = mb_strimwidth($products ?: 'سفارش فروشگاه', 0, 100, '…');
            }
            $phone = $this->normalizePhone($order->customer?->contact_phone);
            if ($phone === null && $order->customer_account_id) {
                $account = CustomerAccount::query()->find($order->customer_account_id);
                if ($account?->is_active && $account->verified_at) {
                    $phone = $this->normalizePhone($account->phone);
                }
            }
            $this->patternSms->send($event, $order->public_id, $phone,
                ['order_code' => strtoupper(substr($order->public_id, 0, 8))] + $variables);
        }, $event);
    }

    public function repairCreatedPattern(RepairRequest|RepairCase $repair): void
    {
        $this->safe(function () use ($repair): void {
            $code = (string) $repair->tracking_reference;
            $this->patternSms->send('repair_created', $code, $this->normalizePhone($repair->contact_phone), [
                'name' => (string) $repair->customer_name,
                'repair_code' => $code,
            ]);
        }, 'repair_created');
    }

    public function repairStagePattern(RepairCase $case): void
    {
        $this->safe(function () use ($case): void {
            $cancelled = $case->status === RepairStage::Returned;
            $variables = ['repair_code' => (string) $case->tracking_reference];
            if (! $cancelled) {
                $variables['status'] = $case->status->label();
            }
            $this->patternSms->send($cancelled ? 'repair_cancelled' : 'repair_status',
                $case->public_id.':'.$case->version, $this->normalizePhone($case->contact_phone), $variables);
        }, 'repair_stage_pattern');
    }

    public function repairRequestSubmitted(RepairRequest $repair, ?string $customerEmail = null): void
    {
        $this->repairCreatedPattern($repair);
        $this->safe(function () use ($repair, $customerEmail): void {
            $ref = FaNumber::digits((string) $repair->tracking_reference);
            $customerName = (string) $repair->customer_name;
            $customerPhone = $this->normalizePhone($repair->contact_phone);
            $device = trim($repair->device_brand->value.' · '.$repair->device_model);
            $adminUrl = route('admin.repairs.show', $repair);

            $adminFields = [
                'کد پیگیری' => $ref,
                'نام مشتری' => $customerName,
                'دستگاه' => $device,
                'شرح مشکل' => mb_strimwidth(trim((string) $repair->issue), 0, 140, '…'),
            ];
            $botText = $this->formatBotMessage('🔧 درخواست تعمیر جدید ثبت شد', $adminFields, $adminUrl);

            $this->sendAdminSms('admin_repair_submitted', ['code' => (string) $repair->tracking_reference, 'device' => $device]);
            $this->sendAdminEmail('درخواست تعمیر جدید #'.$ref, 'ثبت درخواست تعمیر آنلاین', 'یک درخواست تعمیر جدید توسط مشتری ثبت شد.', $adminFields, 'مشاهده درخواست در پنل', $adminUrl);
            $this->bale->send($botText);
            $this->telegram->send($botText);

            if ($customerPhone !== null && $this->patternSms->configurationIssue() !== null) {
                $this->sendSms($customerPhone, 'customer_repair_submitted', ['code' => (string) $repair->tracking_reference, 'device' => $device]);
            }
            $this->sendCustomerEmail(
                $customerEmail,
                'درخواست تعمیر شما ثبت شد (کد پیگیری: '.$ref.')',
                'درخواست تعمیر دستگاه ثبت شد',
                'درخواست شما با موفقیت ثبت شد. می‌توانید وضعیت آن را با کد پیگیری زیر دنبال کنید.',
                ['کد پیگیری' => $ref, 'دستگاه' => $device],
                'پیگیری درخواست تعمیر',
                route('repair.track'),
            );
        }, 'repair_request_submitted');
    }

    public function repairStatusUpdated(
        RepairCase $case,
        string $statusLabel,
        ?int $costToman = null,
        ?string $tracking = null,
        ?string $customerEmail = null,
    ): void {
        $this->safe(function () use ($case, $statusLabel, $costToman, $tracking, $customerEmail): void {
            $ref = (string) $case->tracking_reference;
            $refFa = FaNumber::digits($ref);
            $customerPhone = $this->normalizePhone($case->contact_phone);
            $costText = $costToman !== null ? FaNumber::digits(number_format($costToman)).' تومان' : 'ثبت نشده';

            if ($customerPhone !== null && $this->patternSms->configurationIssue() !== null) {
                $params = ['code' => $ref, 'status' => $statusLabel];
                if ($costToman !== null) {
                    $params['cost'] = (string) $costToman;
                }
                if (trim((string) $tracking) !== '') {
                    $params['tracking'] = trim((string) $tracking);
                }
                $this->sendSms($customerPhone, 'customer_repair_status', $params);
            }

            $fields = ['کد پیگیری' => $refFa, 'وضعیت جدید' => $statusLabel];
            if ($costToman !== null) {
                $fields['هزینه تعمیر'] = $costText;
            }
            if (trim((string) $tracking) !== '') {
                $fields['کد پیگیری ارسال'] = trim((string) $tracking);
            }

            $this->sendCustomerEmail(
                $customerEmail,
                'به‌روزرسانی وضعیت تعمیر #'.$refFa.' ('.$statusLabel.')',
                'وضعیت پرونده تعمیر شما به‌روزرسانی شد',
                'آخرین وضعیت پرونده تعمیر دستگاه شما در سامانه ثبت شد.',
                $fields,
                'پیگیری تعمیر',
                route('repair.track'),
            );
        }, 'repair_status_updated');
    }

    public function consultationSubmitted(Consultation $consultation, ?string $customerEmail = null): void
    {
        $this->safe(function () use ($consultation, $customerEmail): void {
            $customerName = (string) $consultation->customer_name;
            $customerPhone = $this->normalizePhone($consultation->contact_phone);
            $topicKey = (string) ($consultation->topic ?? '');
            $topic = Consultation::TOPICS[$topicKey] ?? ($topicKey !== '' ? $topicKey : 'مشاوره عمومی');
            $adminUrl = route('admin.consultations.show', $consultation);

            $adminFields = [
                'نام متقاضی' => $customerName,
                'موضوع' => $topic,
                'پرسش' => mb_strimwidth(trim((string) $consultation->question), 0, 140, '…'),
            ];
            $botText = $this->formatBotMessage('💬 درخواست مشاوره جدید ثبت شد', $adminFields, $adminUrl);

            $this->sendAdminSms('admin_consultation_submitted', ['name' => $customerName, 'topic' => $topic]);
            $this->sendAdminEmail('درخواست مشاوره جدید از '.$customerName, 'ثبت درخواست مشاوره', 'یک درخواست مشاوره جدید در وب‌سایت ثبت شد.', $adminFields, 'مشاهده درخواست در پنل', $adminUrl);
            $this->bale->send($botText);
            $this->telegram->send($botText);

            if ($customerPhone !== null) {
                $this->sendSms($customerPhone, 'customer_consultation_submitted', ['name' => $customerName, 'topic' => $topic]);
            }
            $this->sendCustomerEmail(
                $customerEmail,
                'درخواست مشاوره شما در '.config('business.name', 'نوین‌پی').' ثبت شد',
                'درخواست مشاوره شما دریافت شد',
                'کارشناسان ما در کوتاه‌ترین زمان با شما تماس خواهند گرفت.',
                ['موضوع' => $topic],
            );
        }, 'consultation_submitted');
    }

    public function orderPaid(SalesOrder $order, FinancialDocument $document, PaymentTransaction $transaction): void
    {
        $this->safe(function () use ($order, $document, $transaction): void {
            if ($document->charged_minor <= 0 || $document->paid_minor < $document->charged_minor || $transaction->allocated_minor <= 0) {
                return;
            }

            $orderLockKey = 'novinpay:notify:order_paid:order:'.$order->public_id;
            $txLockKey = 'novinpay:notify:order_paid:'.$transaction->public_id;
            if (! Cache::add($orderLockKey, true, now()->addDays(7)) || ! Cache::add($txLockKey, true, now()->addDays(7))) {
                return;
            }

            $order->loadMissing(['customer', 'lines']);
            $code = strtoupper(substr((string) $order->public_id, 0, 8));
            $customerName = (string) ($order->customer?->name ?? 'مشتری');
            $customerMobile = $this->normalizePhone($order->customer?->contact_phone) ?? 'ثبت نشده';
            $amountToman = $this->formatIntegerToman($transaction->amount_minor);
            $trackId = $this->resolveZibalTrackId($transaction);

            $itemSummary = $order->lines->isNotEmpty()
                ? $order->lines->map(fn ($line) => trim((string) $line->description).' × '.FaNumber::digits((int) $line->quantity))->implode('، ')
                : '—';

            $plan = DeliveryPlan::query()->where('financial_document_id', $document->id)->first();
            $shippingMethodKey = (string) ($plan?->requested_method ?: $plan?->method ?: 'pickup');
            $shippingLabel = match ($shippingMethodKey) {
                'pickup' => 'تحویل حضوری در فروشگاه نوین پی',
                'post' => 'پست',
                'tipax' => 'تیپاکس',
                'courier' => 'پیک',
                default => $shippingMethodKey,
            };

            $adminUrl = route('admin.orders.show', $order);
            $fields = [
                'کد سفارش' => $code,
                'مشتری' => $customerName,
                'موبایل' => FaNumber::digits($customerMobile),
                'اقلام سفارش' => $itemSummary,
                'نحوه ارسال' => $shippingLabel,
                'مبلغ پرداخت‌شده' => $amountToman,
                'کد رهگیری زیبال' => $trackId !== '' ? FaNumber::digits($trackId) : '—',
            ];

            $botText = $this->formatBotMessage('✅ پرداخت آنلاین سفارش تأیید شد', $fields, $adminUrl);
            $this->telegram->send($botText);
            $this->bale->send($botText);
        }, 'order_paid');
        if ($order->status !== 'cancelled' && $document->charged_minor > 0
            && $document->paid_minor >= $document->charged_minor && $transaction->allocated_minor > 0) {
            $this->orderPattern($order, 'order_confirmed');
        }
    }

    public function repairPaid(RepairCase $repairCase, FinancialDocument $document, PaymentTransaction $transaction): void
    {
        $this->safe(function () use ($repairCase, $transaction): void {
            $lockKey = 'novinpay:notify:repair_paid:'.$transaction->public_id;
            if (! Cache::add($lockKey, true, now()->addDays(7))) {
                return;
            }

            $ref = (string) $repairCase->tracking_reference;
            $customerName = (string) ($repairCase->customer_name ?: 'مشتری');
            $customerMobile = $this->normalizePhone($repairCase->contact_phone) ?? 'ثبت نشده';
            $device = trim(($repairCase->device_brand?->value ? $repairCase->device_brand->value.' · ' : '').(string) $repairCase->device_model);
            $amountToman = $this->formatIntegerToman($transaction->amount_minor);
            $trackId = $this->resolveZibalTrackId($transaction);
            $adminUrl = route('admin.repair-cases.show', $repairCase);

            $fields = [
                'کد پیگیری تعمیر' => FaNumber::digits($ref),
                'مشتری' => $customerName,
                'موبایل' => FaNumber::digits($customerMobile),
                'مدل دستگاه' => $device !== '' ? $device : '—',
                'مبلغ پرداخت‌شده' => $amountToman,
                'کد رهگیری زیبال' => $trackId !== '' ? FaNumber::digits($trackId) : '—',
            ];

            $botText = $this->formatBotMessage('✅ هزینه تعمیر آنلاین پرداخت شد', $fields, $adminUrl);
            $this->telegram->send($botText);
            $this->bale->send($botText);
        }, 'repair_paid');
    }

    public function repairPaymentLinkGenerated(RepairCase $repairCase, FinancialDocument $document, string $paymentUrl): void
    {
        $this->safe(function () use ($repairCase, $document, $paymentUrl): void {
            parse_str((string) parse_url($paymentUrl, PHP_URL_QUERY), $query);
            $expires = (int) ($query['expires'] ?? 0);
            if ($expires <= now()->timestamp) {
                return;
            }
            $this->patternSms->send('repair_payment', $document->public_id.':'.$document->version,
                $this->normalizePhone($repairCase->contact_phone), [
                    'repair_code' => (string) $repairCase->tracking_reference,
                    'pay_code' => RepairPaymentCode::issue($repairCase->public_id, $expires),
                ]);
        }, 'repair_payment');
        $this->safe(function () use ($repairCase, $document, $paymentUrl): void {
            $ref = (string) $repairCase->tracking_reference;
            $customerName = (string) ($repairCase->customer_name ?: 'مشتری');
            $customerMobile = $this->normalizePhone($repairCase->contact_phone) ?? 'ثبت نشده';
            $balanceMinor = max(0, (int) ($document->charged_minor - $document->paid_minor));
            $amountToman = $this->formatIntegerToman($balanceMinor);

            $fields = [
                'کد پیگیری تعمیر' => FaNumber::digits($ref),
                'مشتری' => $customerName,
                'موبایل' => FaNumber::digits($customerMobile),
                'مبلغ قابل پرداخت' => $amountToman,
                'لینک پرداخت' => $paymentUrl,
            ];

            $botText = $this->formatBotMessage('🔗 لینک پرداخت آنلاین تعمیر ساخته شد', $fields);
            $this->telegram->send($botText);
            $this->bale->send($botText);
        }, 'repair_payment_link_generated');
    }

    private function formatIntegerToman(int $minor): string
    {
        return FaNumber::digits(number_format(intdiv($minor, 100), 0, '.', '٬')).' تومان';
    }

    private function resolveZibalTrackId(PaymentTransaction $transaction): string
    {
        if ($transaction->payment_attempt_id) {
            $track = PaymentAttempt::query()->whereKey($transaction->payment_attempt_id)->value('track_id');
            if (is_string($track) && $track !== '') {
                return $track;
            }
        }

        return (string) ($transaction->reference ?? '');
    }

    public function supportChatMessage(string $conversationId, string $messagePreview, ?string $senderName = null): void
    {
        $this->safe(function () use ($conversationId, $messagePreview, $senderName): void {
            $id = trim($conversationId);
            if ($id === '') {
                return;
            }
            $preview = mb_strimwidth(trim($messagePreview) !== '' ? trim($messagePreview) : '[پیوست تصویری]', 0, 180, '…');
            $name = trim((string) $senderName) !== '' ? trim((string) $senderName) : 'کاربر وب‌سایت';
            $supportUrl = url('/support');

            $fields = [
                'فرستنده' => $name,
                'متن پیام' => $preview,
            ];
            $botText = $this->formatBotMessage('📩 پیام جدید در چت پشتیبانی', $fields, $supportUrl);

            // Always alert admins via Bale and Telegram on customer chat messages.
            $this->bale->send($botText);
            $this->telegram->send($botText);

            // Send IPPanel SMS ONLY on the first message per user/conversation.
            if ($this->canSendAdminSms('admin_chat_first_message')) {
                $hash = hash_hmac('sha256', 'chat-first-sms:'.$id, (string) config('app.key'));
                $inserted = DB::table('support_chat_notifications')->insertOrIgnore([
                    'conversation_hash' => $hash,
                    'first_sms_sent_at' => now()->utc(),
                    'created_at' => now()->utc(),
                ]);
                if ($inserted === 1) {
                    $this->sendAdminSms('admin_chat_first_message', ['name' => $name]);
                }
            }
        }, 'support_chat_message');
    }

    private function canSendAdminSms(string $patternKey): bool
    {
        if (! config('notifications.sms.enabled') || ! config('customer.ippanel.enabled')) {
            return false;
        }
        $pattern = trim((string) config('notifications.sms.patterns.'.$patternKey, ''));
        $phones = $this->adminPhones();

        return $pattern !== '' && $phones !== [];
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private function sendAdminSms(string $patternKey, array $params): void
    {
        if (! $this->canSendAdminSms($patternKey)) {
            return;
        }
        foreach ($this->adminPhones() as $phone) {
            $this->sendSms($phone, $patternKey, $params);
        }
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private function sendSms(string $phone, string $patternKey, array $params): void
    {
        if (! config('notifications.sms.enabled') || ! config('customer.ippanel.enabled')) {
            return;
        }
        $pattern = trim((string) config('notifications.sms.patterns.'.$patternKey, ''));
        if ($pattern === '') {
            return;
        }
        try {
            $this->ipPanel->sendPattern($phone, $pattern, $params);
        } catch (\Throwable $e) {
            Log::warning('Notification SMS dispatch failed', ['pattern_key' => $patternKey, 'exception' => $e::class]);
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function sendAdminEmail(
        string $subject,
        string $heading,
        string $intro,
        array $fields,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
    ): void {
        if (! config('notifications.email.enabled')) {
            return;
        }
        foreach ($this->adminEmails() as $email) {
            $this->sendMail($email, $subject, $heading, $intro, $fields, $actionLabel, $actionUrl);
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function sendCustomerEmail(
        ?string $email,
        string $subject,
        string $heading,
        string $intro,
        array $fields = [],
        ?string $actionLabel = null,
        ?string $actionUrl = null,
    ): void {
        if (! config('notifications.email.enabled')) {
            return;
        }
        $clean = trim((string) $email);
        if ($clean === '' || ! filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $this->sendMail($clean, $subject, $heading, $intro, $fields, $actionLabel, $actionUrl);
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function sendMail(
        string $recipient,
        string $subject,
        string $heading,
        string $intro,
        array $fields,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
    ): void {
        try {
            Mail::to($recipient)->send(new UnifiedNotificationMail(
                mailSubject: $subject,
                heading: $heading,
                intro: $intro,
                fields: $fields,
                actionLabel: $actionLabel,
                actionUrl: $actionUrl,
            ));
        } catch (\Throwable $e) {
            Log::warning('Notification email dispatch failed', ['exception' => $e::class]);
        }
    }

    /**
     * @return list<string>
     */
    private function adminPhones(): array
    {
        $phones = [];
        foreach ((array) config('notifications.admin.phones', []) as $raw) {
            $normalized = $this->normalizePhone(is_string($raw) ? $raw : null);
            if ($normalized !== null) {
                $phones[] = $normalized;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @return list<string>
     */
    private function adminEmails(): array
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

    private function normalizePhone(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }
        $digits = strtr(trim($phone), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/[^0-9+]/', '', $digits) ?? '';
        if (str_starts_with($digits, '+98') && strlen($digits) === 13) {
            $digits = '0'.substr($digits, 3);
        } elseif (str_starts_with($digits, '98') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        return preg_match('/\A09[0-9]{9}\z/D', $digits) === 1 ? $digits : null;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function formatBotMessage(string $title, array $fields, ?string $url = null): string
    {
        $lines = [$title, ''];
        foreach ($fields as $label => $value) {
            $lines[] = "• {$label}: {$value}";
        }
        if ($url) {
            $lines[] = '';
            $lines[] = $url;
        }

        return implode("\n", $lines);
    }

    private function safe(callable $callback, string $event): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('Unified notification handler failed safely', [
                'event' => $event,
                'exception' => $e::class,
            ]);
        }
    }
}
