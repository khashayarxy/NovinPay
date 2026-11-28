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
use App\Models\Product;
use App\Models\ProductFeedback;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use App\Services\ActivationDocumentsService;
use App\Services\IpPanel;
use App\Services\Observability\ObservabilityEvents;
use App\Services\Observability\TelegramTopicNotifier;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use App\Support\RepairPaymentCode;
use App\Support\RepairTracking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class UnifiedNotifier
{
    public function __construct(
        private readonly IpPanel $ipPanel,
        private readonly BaleNotifier $bale,
        private readonly TelegramNotifier $telegram,
        private readonly PatternSms $patternSms,
        private readonly TelegramTopicNotifier $telegramTopics,
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
            $productsSummary = mb_strimwidth($order->lines->pluck('description')->filter()->unique()->implode('، ') ?: 'سفارش فروشگاه', 0, 75, '…');
            $adminUrl = route('admin.orders.show', $order);

            $adminFields = [
                'شماره سفارش' => $code,
                'نام مشتری' => $customerName,
                'شماره تماس' => $customerPhone ? FaNumber::digits($customerPhone) : 'ثبت نشده',
                'تعداد اقلام' => $itemCount,
                'مبلغ کل' => $totalToman,
                'وضعیت' => 'ثبت جدید — در انتظار بررسی / پرداخت',
                'زمان ثبت (تهران)' => $this->tehranTimestamp(),
            ];

            $this->sendAdminSms(
                patternKey: 'admin_order_placed',
                params: ['code' => $code, 'amount' => $totalToman, 'name' => $customerName],
                dedupeIdentity: 'order_placed:'.$order->public_id,
            );
            $this->sendAdminEmail('سفارش جدید #'.$code, 'ثبت سفارش خرید جدید', 'یک سفارش جدید در فروشگاه ثبت شد.', $adminFields, 'مشاهده سفارش در پنل', $adminUrl, 'سفارش جدید', 'info');

            if ($customerPhone !== null && $this->patternSms->configurationIssue() !== null) {
                $this->sendSms($customerPhone, 'customer_order_placed', ['code' => $code, 'total' => $totalToman]);
            }
            $resolvedEmail = $this->resolveOrderCustomerEmail($order, $customerEmail);
            $this->sendCustomerEmail(
                $resolvedEmail,
                'ثبت سفارش شما در '.config('business.name', 'نوین‌پی').' (#'.$code.')',
                'سفارش شما با موفقیت ثبت شد',
                'سفارش شما دریافت شد و پس از بررسی موجودی و تایید، مراحل ارسال اطلاع‌رسانی خواهد شد.',
                ['شماره سفارش' => $code, 'مبلغ کل' => $totalToman, 'وضعیت' => 'دریافت‌شده', 'زمان ثبت' => $this->tehranTimestamp()],
                'پیگیری در حساب کاربری',
                route('account.orders.show', $order->public_id),
                'ثبت سفارش',
                'info',
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
            $resolvedEmail = $this->resolveOrderCustomerEmail($order, $customerEmail);
            $this->sendCustomerEmail(
                $resolvedEmail,
                'سفارش شما ارسال شد (#'.$code.')',
                'سفارش شما تحویل شرکت حمل شد',
                'سفارش شما ارسال شده است و می‌توانید وضعیت آن را با کد پیگیری زیر دنبال کنید.',
                ['شماره سفارش' => $code, 'کد پیگیری ارسال' => $trackingText],
                'مشاهده سفارش در حساب کاربری',
                route('account.orders.show', $order->public_id),
                'ارسال سفارش',
                'info',
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
            $phone = null;
            if ($order->customer_account_id) {
                $account = CustomerAccount::query()->find($order->customer_account_id);
                $phone = $account?->is_active && $account->verified_at
                    ? $this->normalizePhone($account->phone) : null;
            }
            $phone ??= $this->normalizePhone($order->customer?->contact_phone);
            $this->patternSms->send($event, $order->public_id, $phone,
                ['order_code' => strtoupper(substr($order->public_id, 0, 8))] + $variables);
        }, $event);
    }

    public function repairCreatedPattern(RepairRequest|RepairCase $repair): void
    {
        $this->safe(function () use ($repair): void {
            $code = (string) $repair->tracking_reference;
            $event = null;
            if ($repair instanceof RepairRequest) {
                $handoverKey = $repair->parsedIssue()['handover_key'] ?? null;
                $event = match ($handoverKey) {
                    'in_person' => 'repair_created_in_person',
                    'self_ship', 'postal', 'post', 'tipax' => 'repair_created_postal',
                    'doorstep_pickup', 'courier' => 'repair_created_courier',
                    default => null,
                };
            } else {
                $event = match ((string) ($repair->inbound_method ?? '')) {
                    'in_person' => 'repair_created_in_person',
                    'post', 'tipax' => 'repair_created_postal',
                    'courier' => 'repair_created_courier',
                    default => null,
                };
            }

            if ($event === null) {
                return;
            }

            $this->patternSms->send($event, $code, $this->normalizePhone($repair->contact_phone), [
                'name' => (string) $repair->customer_name,
                'repair_code' => $code,
            ]);
        }, 'repair_created_flow_pattern');
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

    public function repairActivationDocsRequested(RepairCase $case, string $phone, string $code): void
    {
        $this->safe(function () use ($phone, $code): void {
            $normalized = $this->normalizePhone($phone);
            if ($normalized === null) {
                return;
            }
            $this->sendIpPanelOptionalPattern($normalized, 'documents_request', [
                'code' => $code,
            ]);
        }, 'repair_activation_docs_requested');
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

            $parsed = $repair->parsedIssue();
            $adminFields = [
                'کد پیگیری' => $ref,
                'نام مشتری' => $customerName,
                'شماره تماس' => $customerPhone ? FaNumber::digits($customerPhone) : '—',
                'دستگاه' => $device,
            ];
            if (! empty($parsed['handover_label'])) {
                $adminFields['نحوه تحویل'] = $parsed['handover_label'];
            }
            if (! empty($parsed['pickup_notes'])) {
                $adminFields['توضیحات پیک'] = mb_strimwidth($parsed['pickup_notes'], 0, 140, '…');
            }
            $adminFields['شرح مشکل'] = mb_strimwidth(trim((string) ($parsed['problem'] ?: $repair->issue)), 0, 140, '…');
            $adminFields['وضعیت'] = 'ثبت جدید — در انتظار بررسی';
            $adminFields['زمان ثبت (تهران)'] = $this->tehranTimestamp();
            $botText = $this->formatBotMessage('🔧 درخواست تعمیر جدید ثبت شد', $adminFields, $adminUrl);

            $this->sendAdminSms(
                patternKey: 'admin_repair_submitted',
                params: ['code' => (string) $repair->tracking_reference, 'device' => $device, 'name' => $customerName],
                dedupeIdentity: 'repair_submitted:'.$repair->tracking_reference,
            );
            $this->sendAdminEmail('درخواست تعمیر جدید #'.$ref, 'ثبت درخواست تعمیر آنلاین', 'یک درخواست تعمیر جدید توسط مشتری ثبت شد.', $adminFields, 'مشاهده درخواست در پنل', $adminUrl, 'درخواست تعمیر جدید', 'info');
            $this->bale->send($botText, 'repair_req:'.$repair->tracking_reference, 300, $adminUrl);

            if ($customerPhone !== null && config('notifications.pattern_sms.enabled') === false) {
                $this->sendSms($customerPhone, 'customer_repair_submitted', ['code' => (string) $repair->tracking_reference, 'device' => $device]);
            }
            $resolvedEmail = $this->resolveRepairCustomerEmail($repair, $customerEmail);
            $customerFields = ['کد پیگیری' => $ref, 'دستگاه' => $device];
            if (! empty($parsed['handover_label'])) {
                $customerFields['نحوه تحویل'] = $parsed['handover_label'];
            }
            $this->sendCustomerEmail(
                $resolvedEmail,
                'درخواست تعمیر شما ثبت شد (کد پیگیری: '.$ref.')',
                'درخواست تعمیر دستگاه ثبت شد',
                'درخواست شما با موفقیت ثبت شد. می‌توانید وضعیت آن را با کد پیگیری زیر دنبال کنید.',
                $customerFields,
                'پیگیری درخواست تعمیر',
                RepairTracking::url((string) $repair->tracking_reference),
                'ثبت درخواست تعمیر',
                'info',
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
                $repairLink = RepairTracking::url($ref);
                $doc = FinancialDocument::query()->where('repair_case_id', $case->id)->first();
                if ($doc && ($doc->charged_minor - $doc->paid_minor) > 0) {
                    $repairLink = route('payments.repair.short', [
                        'code' => RepairPaymentCode::issue($case->public_id, now()->addDays(14)->timestamp),
                    ]);
                }
                $params = ['code' => $ref, 'status' => $statusLabel, 'link' => $repairLink];
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

            $doc = FinancialDocument::query()->where('repair_case_id', $case->id)->first();
            $hasUnpaidBalance = $doc && ($doc->charged_minor - $doc->paid_minor) > 0;
            $actionLabel = $hasUnpaidBalance ? 'مشاهده و پرداخت آنلاین هزینه تعمیر' : 'پیگیری تعمیر در حساب کاربری';
            $actionUrl = $case->customer_account_id
                ? route('account.repairs.show', $case->public_id)
                : ($hasUnpaidBalance
                    ? route('payments.repair.short', ['code' => RepairPaymentCode::issue($case->public_id, now()->addDays(14)->timestamp)])
                    : RepairTracking::url($ref));

            $resolvedEmail = $this->resolveRepairCustomerEmail($case, $customerEmail);
            $this->sendCustomerEmail(
                $resolvedEmail,
                'به‌روزرسانی وضعیت تعمیر #'.$refFa.' ('.$statusLabel.')',
                'وضعیت پرونده تعمیر شما به‌روزرسانی شد',
                $hasUnpaidBalance
                    ? 'هزینهٔ تعمیر دستگاه شما تعیین شده است و می‌توانید از طریق حساب کاربری یا لینک زیر آنلاین تسویه کنید.'
                    : 'آخرین وضعیت پرونده تعمیر دستگاه شما در سامانه ثبت شد.',
                $fields,
                $actionLabel,
                $actionUrl,
                $statusLabel,
                $hasUnpaidBalance ? 'warning' : 'info',
            );
        }, 'repair_status_updated');
    }

    public function consultationSubmitted(Consultation $consultation, ?string $customerEmail = null): void
    {
        $this->safe(function () use ($consultation, $customerEmail): void {
            app(ObservabilityEvents::class)->consultationCreated();

            $customerName = (string) $consultation->customer_name;
            $customerPhone = $this->normalizePhone($consultation->contact_phone);
            $topicKey = (string) ($consultation->topic ?? '');
            $topic = Consultation::TOPICS[$topicKey] ?? ($topicKey !== '' ? $topicKey : 'مشاوره عمومی');
            $adminUrl = route('admin.consultations.show', $consultation);

            $adminFields = [
                'کد درخواست' => strtoupper(substr((string) $consultation->public_id, 0, 8)),
                'نام متقاضی' => $customerName,
                'موبایل' => $customerPhone ? FaNumber::digits($customerPhone) : '—',
                'موضوع' => $topic,
                'پرسش' => mb_strimwidth(trim((string) $consultation->question), 0, 140, '…'),
                'وضعیت' => 'در انتظار تماس کارشناس',
                'زمان ثبت (تهران)' => $this->tehranTimestamp(),
            ];
            $botText = $this->formatBotMessage('💬 درخواست مشاوره جدید ثبت شد', $adminFields, $adminUrl);

            $this->sendAdminSms(
                'admin_consultation_submitted',
                [
                    'name' => $customerName,
                    'phone' => $customerPhone ? FaNumber::digits($customerPhone) : 'ثبت‌نشده',
                    'topic' => $topic,
                ],
                'consult:'.$consultation->public_id,
            );
            $this->sendAdminEmail('درخواست مشاوره جدید از '.$customerName, 'ثبت درخواست مشاوره', 'یک درخواست مشاوره جدید در وب‌سایت ثبت شد.', $adminFields, 'مشاهده درخواست در پنل', $adminUrl, 'درخواست مشاوره', 'info');
            $this->bale->send($botText, 'consult:'.$consultation->public_id, 300, $adminUrl);

            if ($this->telegramTopics->enabledFor('consultations')) {
                $this->telegramTopics->send('consultations', $botText, 'consult:'.$consultation->public_id, 300, $adminUrl);
            } else {
                $this->telegram->send($botText);
            }

            if ($customerPhone !== null) {
                $consultationPatternKey = trim((string) config('notifications.sms.patterns.consultation_submitted', '')) !== ''
                    ? 'consultation_submitted'
                    : 'customer_consultation_submitted';
                $this->sendIpPanelOptionalPattern($customerPhone, $consultationPatternKey, [
                    'name' => $customerName,
                ]);
            }
            $resolvedEmail = $this->resolvePhoneCustomerEmail($customerPhone, $customerEmail);
            $this->sendCustomerEmail(
                $resolvedEmail,
                'درخواست مشاوره شما در '.config('business.name', 'نوین‌پی').' ثبت شد',
                'درخواست مشاوره شما دریافت شد',
                'کارشناسان ما در کوتاه‌ترین زمان با شما تماس خواهند گرفت.',
                ['نام متقاضی' => $customerName, 'موضوع' => $topic],
                'مشاهده فروشگاه',
                route('products.index'),
                'درخواست مشاوره',
                'info',
            );
        }, 'consultation_submitted');
    }

    public function productFeedbackSubmitted(ProductFeedback $entry, Product $product, ?CustomerAccount $author = null): void
    {
        $this->safe(function () use ($entry, $product, $author): void {
            app(ObservabilityEvents::class)->reviewCreated();

            $isReview = $entry->kind === 'review';
            $authorName = trim((string) ($author?->name ?? '')) !== '' ? trim((string) $author->name) : 'کاربر تأییدشده';
            $adminUrl = route('admin.products.feedback.index', $product);
            $fields = [
                'محصول' => (string) $product->name,
                'نوع ثبت' => $isReview ? 'دیدگاه محصول' : 'پرسش محصول',
                'نویسنده' => $authorName.($entry->verified_buyer ? ' (خریدار تأییدشده)' : ''),
            ];
            if ($entry->verified_buyer && $entry->rating) {
                $fields['امتیاز'] = FaNumber::digits((int) $entry->rating).' از ۵';
            }
            $fields['متن'] = mb_strimwidth(trim((string) $entry->body), 0, 180, '…');

            $title = $isReview ? '⭐ دیدگاه جدید برای بررسی ثبت شد' : '❓ پرسش جدید محصول ثبت شد';
            $botText = $this->formatBotMessage($title, $fields, $adminUrl);
            $dedupeKey = 'product_feedback:'.$product->id.':'.$entry->id.':'.md5((string) $entry->body);

            if ($this->telegramTopics->enabledFor('reviews')) {
                $this->telegramTopics->send('reviews', $botText, $dedupeKey, 60, $adminUrl, 'بررسی و تأیید دیدگاه');
            } else {
                $this->telegram->send($botText);
            }

            $this->bale->send($botText, $dedupeKey, 60, $adminUrl, 'بررسی و تأیید دیدگاه');
            $this->sendAdminEmail(
                ($isReview ? 'دیدگاه جدید محصول: ' : 'پرسش جدید محصول: ').$product->name,
                $isReview ? 'ثبت دیدگاه جدید در انتظار تأیید' : 'ثبت پرسش جدید محصول',
                'یک دیدگاه جدید توسط کاربر ثبت شد و پس از تأیید شما در صفحه محصول نمایش داده خواهد شد.',
                $fields + ['وضعیت' => 'در انتظار تأیید مدیریت', 'زمان ثبت (تهران)' => $this->tehranTimestamp()],
                'مدیریت دیدگاه‌ها',
                $adminUrl,
                'در انتظار تأیید',
                'warning',
            );
        }, 'product_feedback_submitted');
    }

    public function orderDocumentsApproved(SalesOrder $order): void
    {
        $this->safe(function () use ($order): void {
            $order->loadMissing('customer');
            $code = strtoupper(substr((string) $order->public_id, 0, 8));
            $phone = $this->resolveOrderCustomerPhone($order);
            if ($phone !== null) {
                $this->sendIpPanelOptionalPattern($phone, 'documents_approved', [
                    'code' => $code,
                ]);
            }

            $customerEmail = $this->resolveOrderCustomerEmail($order);
            $this->sendCustomerEmail(
                $customerEmail,
                'تأیید مدارک فعال‌سازی سفارش #'.$code,
                'مدارک فعال‌سازی شما تأیید شد',
                'مدارک ارسالی شما بررسی و تأیید شد. فرآیند فعال‌سازی و آماده‌سازی دستگاه شما در حال انجام است.',
                ['کد سفارش' => $code, 'وضعیت مدارک' => 'تأیید شده'],
                'مشاهده وضعیت سفارش',
                route('account.orders.show', $order->public_id),
                'مدارک تأیید شد',
                'success',
            );
        }, 'order_documents_approved');
    }

    public function orderDocumentsRevision(SalesOrder $order, string $reason): void
    {
        $this->safe(function () use ($order, $reason): void {
            $order->loadMissing('customer');
            $code = strtoupper(substr((string) $order->public_id, 0, 8));
            $cleanReason = trim($reason);
            $phone = $this->resolveOrderCustomerPhone($order);
            if ($phone !== null) {
                $this->sendIpPanelOptionalPattern($phone, 'documents_revision', [
                    'code' => $code,
                    'reason' => $cleanReason !== '' ? Str::limit($cleanReason, 120) : 'نقص یا ناخوانا بودن مدارک',
                ]);
            }

            $customerEmail = $this->resolveOrderCustomerEmail($order);
            $this->sendCustomerEmail(
                $customerEmail,
                'نیاز به اصلاح مدارک فعال‌سازی سفارش #'.$code,
                'مدارک فعال‌سازی نیازمند اصلاح است',
                'لطفاً توضیح زیر را مطالعه کرده و تصویر اصلاح‌شدهٔ مدارک را از طریق حساب کاربری بارگذاری فرمایید.',
                [
                    'کد سفارش' => $code,
                    'وضعیت مدارک' => 'نیاز به اصلاح',
                    'علت اعلام‌شده' => $cleanReason !== '' ? $cleanReason : 'نیاز به بارگذاری مجدد تصویر خوانا',
                ],
                'اصلاح و ارسال مجدد مدارک',
                route('account.orders.show', $order->public_id),
                'نیاز به اصلاح مدارک',
                'warning',
            );
        }, 'order_documents_revision');
    }

    private function accountAllowsEmailNotifications(?CustomerAccount $account): bool
    {
        if (! $account || ! $account->is_active) {
            return false;
        }

        return ($account->email_notifications_enabled ?? true) !== false;
    }

    private function explicitEmailAllowed(string $cleanEmail): bool
    {
        $digest = hash_hmac('sha256', 'customer-email:'.strtolower($cleanEmail), (string) config('app.key'));
        $matching = CustomerAccount::query()->where('email_digest', $digest)->first();
        if ($matching && ($matching->email_notifications_enabled ?? true) === false) {
            return false;
        }

        return true;
    }

    private function resolveOrderCustomerEmail(SalesOrder $order, ?string $explicitEmail = null): ?string
    {
        if (is_string($explicitEmail) && filter_var(trim($explicitEmail), FILTER_VALIDATE_EMAIL)) {
            $clean = trim($explicitEmail);

            return $this->explicitEmailAllowed($clean) ? $clean : null;
        }

        if ($order->customer_account_id) {
            $account = CustomerAccount::query()->find($order->customer_account_id);
            if ($account && ! $this->accountAllowsEmailNotifications($account)) {
                return null;
            }
            if ($this->accountAllowsEmailNotifications($account) && is_string($account->email) && filter_var(trim($account->email), FILTER_VALIDATE_EMAIL)) {
                return trim($account->email);
            }
        }

        if ($order->customer_id) {
            $byCustomer = CustomerAccount::query()
                ->where('customer_id', $order->customer_id)
                ->where('is_active', true)
                ->whereNotNull('email')
                ->latest('id')
                ->first();
            if ($byCustomer && ! $this->accountAllowsEmailNotifications($byCustomer)) {
                return null;
            }
            if ($byCustomer && $this->accountAllowsEmailNotifications($byCustomer) && is_string($byCustomer->email) && filter_var(trim($byCustomer->email), FILTER_VALIDATE_EMAIL)) {
                return trim($byCustomer->email);
            }
        }

        $order->loadMissing('customer');

        return $this->resolvePhoneCustomerEmail($this->normalizePhone($order->customer?->contact_phone));
    }

    private function resolveRepairCustomerEmail(RepairRequest|RepairCase $repair, ?string $explicitEmail = null): ?string
    {
        if (is_string($explicitEmail) && filter_var(trim($explicitEmail), FILTER_VALIDATE_EMAIL)) {
            $clean = trim($explicitEmail);

            return $this->explicitEmailAllowed($clean) ? $clean : null;
        }

        if ($repair->customer_account_id) {
            $account = CustomerAccount::query()->find($repair->customer_account_id);
            if ($account && ! $this->accountAllowsEmailNotifications($account)) {
                return null;
            }
            if ($this->accountAllowsEmailNotifications($account) && is_string($account->email) && filter_var(trim($account->email), FILTER_VALIDATE_EMAIL)) {
                return trim($account->email);
            }
        }

        if ($repair instanceof RepairCase) {
            $onlineReqAccountId = $repair->onlineRequest()->value('customer_account_id');
            if ($onlineReqAccountId) {
                $reqAccount = CustomerAccount::query()->find($onlineReqAccountId);
                if ($reqAccount && ! $this->accountAllowsEmailNotifications($reqAccount)) {
                    return null;
                }
                if ($this->accountAllowsEmailNotifications($reqAccount) && is_string($reqAccount->email) && filter_var(trim($reqAccount->email), FILTER_VALIDATE_EMAIL)) {
                    return trim($reqAccount->email);
                }
            }
            if ($repair->customer_id) {
                $byCustomer = CustomerAccount::query()
                    ->where('customer_id', $repair->customer_id)
                    ->where('is_active', true)
                    ->whereNotNull('email')
                    ->latest('id')
                    ->first();
                if ($byCustomer && ! $this->accountAllowsEmailNotifications($byCustomer)) {
                    return null;
                }
                if ($byCustomer && $this->accountAllowsEmailNotifications($byCustomer) && is_string($byCustomer->email) && filter_var(trim($byCustomer->email), FILTER_VALIDATE_EMAIL)) {
                    return trim($byCustomer->email);
                }
            }
        }

        return $this->resolvePhoneCustomerEmail($this->normalizePhone($repair->contact_phone));
    }

    private function resolvePhoneCustomerEmail(?string $phone, ?string $explicitEmail = null): ?string
    {
        if (is_string($explicitEmail) && filter_var(trim($explicitEmail), FILTER_VALIDATE_EMAIL)) {
            $clean = trim($explicitEmail);

            return $this->explicitEmailAllowed($clean) ? $clean : null;
        }

        $authUser = auth('customer')->user();
        if ($authUser instanceof CustomerAccount && $authUser->is_active && is_string($authUser->email) && filter_var(trim($authUser->email), FILTER_VALIDATE_EMAIL)) {
            $authPhone = $this->normalizePhone($authUser->phone);
            if ($phone === null || $authPhone === null || $authPhone === $phone) {
                return $this->accountAllowsEmailNotifications($authUser) ? trim($authUser->email) : null;
            }
        }

        if ($phone !== null) {
            $digests = [
                RepairIdentity::digest('customer-phone', $phone),
                RepairIdentity::digest('customer-account-phone', $phone),
                RepairIdentity::digest('phone', $phone),
            ];
            $account = CustomerAccount::query()
                ->whereIn('phone_digest', $digests)
                ->where('is_active', true)
                ->whereNotNull('email')
                ->latest('id')
                ->first();
            if ($account && ! $this->accountAllowsEmailNotifications($account)) {
                return null;
            }
            if ($account && $this->accountAllowsEmailNotifications($account) && is_string($account->email) && filter_var(trim($account->email), FILTER_VALIDATE_EMAIL)) {
                return trim($account->email);
            }
        }

        return null;
    }

    private function resolveOrderCustomerPhone(SalesOrder $order): ?string
    {
        if ($order->customer_account_id) {
            $account = CustomerAccount::query()->find($order->customer_account_id);
            if ($account?->is_active && $account->verified_at) {
                $normalized = $this->normalizePhone($account->phone);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return $this->normalizePhone($order->customer?->contact_phone);
    }

    /**
     * Send an optional IPPanel customer pattern SMS (gracefully no-oping when pattern code or IPPanel is unconfigured).
     *
     * @param  array<string, scalar>  $params
     */
    private function sendIpPanelOptionalPattern(string $phone, string $patternKey, array $params): void
    {
        $pattern = trim((string) config('notifications.sms.patterns.'.$patternKey, ''));
        if ($pattern === '') {
            return;
        }
        if ($this->ipPanel->patternConfigurationIssue() !== null) {
            return;
        }

        try {
            $outcome = $this->ipPanel->sendPattern($phone, $pattern, $params);
            app(ObservabilityEvents::class)->sms('notification', $outcome, 'ippanel', $patternKey);
        } catch (\Throwable $e) {
            Log::warning('Optional IPPanel pattern dispatch failed safely', [
                'pattern_key' => $patternKey,
                'exception' => $e::class,
            ]);
        }
    }

    public function orderPaid(SalesOrder $order, FinancialDocument $document, PaymentTransaction $transaction): void
    {
        if ($document->charged_minor <= 0 || $document->paid_minor < $document->charged_minor || $transaction->allocated_minor <= 0) {
            return;
        }
        if ($order->status !== 'cancelled') {
            $this->orderPattern($order, 'order_confirmed');
        }

        $this->safe(function () use ($order, $document, $transaction): void {
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
                'وضعیت پرداخت' => 'پرداخت موفق و تسویه‌شده',
                'زمان تأیید (تهران)' => $this->tehranTimestamp(),
            ];

            $paidPatternKey = trim((string) config('notifications.sms.patterns.admin_order_paid', '')) !== ''
                ? 'admin_order_paid'
                : 'admin_order_placed';
            $this->sendAdminSms(
                patternKey: $paidPatternKey,
                params: ['code' => $code, 'amount' => $amountToman, 'name' => $customerName],
                dedupeIdentity: 'order_paid:'.$order->public_id,
            );
            if ($order->status !== 'cancelled' && app(ActivationDocumentsService::class)->orderRequiresPosActivation($order)) {
                $customerPhoneForDocs = $this->resolveOrderCustomerPhone($order);
                if ($customerPhoneForDocs !== null) {
                    $this->sendIpPanelOptionalPattern($customerPhoneForDocs, 'documents_request', [
                        'code' => $code,
                    ]);
                }
            }
            $this->sendAdminEmail(
                '✅ پرداخت سفارش #'.$code.' تأیید شد',
                'پرداخت آنلاین سفارش با موفقیت تسویه شد',
                'وجه سفارش زیر از طریق درگاه آنلاین پرداخت و تأیید شد.',
                $fields,
                'مشاهده سفارش در پنل',
                $adminUrl,
                'پرداخت موفق',
                'success',
            );

            $botText = $this->formatBotMessage('✅ پرداخت آنلاین سفارش تأیید شد', $fields, $adminUrl);
            $this->telegramTopics->send('orders', $botText, 'order_paid:'.$order->public_id);
            $this->bale->send($botText, 'order_paid:'.$order->public_id, 300, $adminUrl);

            $customerEmail = $this->resolveOrderCustomerEmail($order);
            $this->sendCustomerEmail(
                $customerEmail,
                'تأیید پرداخت و ثبت نهایی سفارش #'.$code,
                'پرداخت سفارش شما با موفقیت انجام شد',
                'وجه سفارش شما تأیید شد. برای تسریع در فعال‌سازی و ثبت مالیاتی دستگاه، می‌توانید مدارک خود را در صفحه سفارش بارگذاری کنید.',
                [
                    'کد سفارش' => $code,
                    'اقلام سفارش' => $itemSummary,
                    'مبلغ پرداخت‌شده' => $amountToman,
                    'کد رهگیری پرداخت' => $trackId !== '' ? FaNumber::digits($trackId) : '—',
                    'نحوه تحویل' => $shippingLabel,
                ],
                'مشاهده سفارش و ارسال مدارک',
                route('account.orders.show', $order->public_id),
                'پرداخت موفق',
                'success',
            );
        }, 'order_paid');
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
                'وضعیت پرداخت' => 'پرداخت موفق هزینه تعمیر',
                'زمان تأیید (تهران)' => $this->tehranTimestamp(),
            ];

            $this->sendAdminEmail(
                '✅ پرداخت هزینه تعمیر #'.FaNumber::digits($ref),
                'هزینه تعمیر آنلاین پرداخت شد',
                'پرداخت آنلاین پرونده تعمیر با موفقیت تأیید شد.',
                $fields,
                'مشاهده پرونده تعمیر',
                $adminUrl,
                'پرداخت تعمیر موفق',
                'success',
            );

            $botText = $this->formatBotMessage('✅ هزینه تعمیر آنلاین پرداخت شد', $fields, $adminUrl);
            $this->telegramTopics->send('repairs', $botText, 'repair_paid:'.$transaction->public_id);
            $this->bale->send($botText, 'repair_paid:'.$transaction->public_id, 300, $adminUrl);

            $customerEmail = $this->resolveRepairCustomerEmail($repairCase);
            $this->sendCustomerEmail(
                $customerEmail,
                'رسید پرداخت هزینه تعمیر #'.FaNumber::digits($ref),
                'هزینه تعمیر دستگاه با موفقیت پرداخت شد',
                'پرداخت آنلاین هزینه تعمیر دستگاه شما ثبت و تأیید شد.',
                [
                    'کد پیگیری تعمیر' => FaNumber::digits($ref),
                    'مدل دستگاه' => $device !== '' ? $device : '—',
                    'مبلغ پرداخت‌شده' => $amountToman,
                    'کد رهگیری پرداخت' => $trackId !== '' ? FaNumber::digits($trackId) : '—',
                ],
                'مشاهده پرونده تعمیر',
                $repairCase->customer_account_id
                    ? route('account.repairs.show', $repairCase->public_id)
                    : RepairTracking::url($ref),
                'پرداخت موفق',
                'success',
            );
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
            $this->telegramTopics->send('repairs', $botText, 'repair_paylink:'.$repairCase->public_id.':'.$document->version);
            $this->bale->send($botText, 'repair_paylink:'.$repairCase->public_id.':'.$document->version, 300);
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

    public function supportChatMessage(string $conversationId, string $messagePreview, ?string $senderName = null, ?string $senderPhone = null): void
    {
        $this->safe(function () use ($conversationId, $messagePreview, $senderName, $senderPhone): void {
            $id = trim($conversationId);
            if ($id === '') {
                return;
            }
            app(ObservabilityEvents::class)->supportChatMessageReceived();

            $preview = mb_strimwidth(trim($messagePreview) !== '' ? trim($messagePreview) : '[پیوست تصویری]', 0, 180, '…');
            $name = trim((string) $senderName) !== '' ? trim((string) $senderName) : 'کاربر وب‌سایت';
            $deepLinkUrl = preg_match('/\Auser_[a-f0-9]{32}_[0-9]{1,20}\z/D', $id) === 1
                ? route('support.panel').'#conversations?id='.rawurlencode($id)
                : route('support.panel');

            $fields = [
                'فرستنده' => $name,
                'شناسه گفتگو' => mb_substr($id, 0, 24),
                'متن پیام' => $preview,
            ];
            $botText = $this->formatBotMessage('📩 پیام جدید در چت پشتیبانی', $fields, $deepLinkUrl);

            // Always alert admins via Bale and Telegram on customer chat messages.
            $this->bale->send($botText, null, null, $deepLinkUrl, 'مشاهده و پاسخ در پنل چت');
            if ($this->telegramTopics->enabledFor('support_chat')) {
                $this->telegramTopics->send('support_chat', $botText, null, null, $deepLinkUrl, 'مشاهده و پاسخ در پنل چت');
            } else {
                $this->telegram->send($botText, $deepLinkUrl, 'مشاهده و پاسخ در پنل چت');
            }

            // Send IPPanel SMS ONLY on the first message per user/conversation.
            if ($this->canSendAdminSms('admin_chat_first_message')) {
                $hash = hash_hmac('sha256', 'chat-first-sms:'.$id, (string) config('app.key'));
                $inserted = DB::table('support_chat_notifications')->insertOrIgnore([
                    'conversation_hash' => $hash,
                    'first_sms_sent_at' => now()->utc(),
                    'created_at' => now()->utc(),
                ]);
                if ($inserted === 1) {
                    $normalizedPhone = $this->normalizePhone($senderPhone);
                    $this->sendAdminSms('admin_chat_first_message', [
                        'name' => $name,
                        'phone' => $normalizedPhone ? FaNumber::digits($normalizedPhone) : 'ثبت‌نشده',
                        'message' => Str::limit(trim($messagePreview) !== '' ? trim($messagePreview) : '[پیوست تصویری]', 45),
                    ]);
                }
            }
        }, 'support_chat_message');
    }

    private function isAdminSmsEnabled(): bool
    {
        return (bool) config('notifications.sms.enabled') || config('notifications.pattern_sms.enabled') === true;
    }

    private function canSendAdminSms(string $patternKey, ?string $fallbackEvent = null): bool
    {
        $phones = $this->adminPhones();
        if ($phones === []) {
            return false;
        }
        if (! $this->isAdminSmsEnabled()) {
            return false;
        }

        $provider = $this->patternSms->provider();
        $providerReady = $provider === 'panelchi'
            ? (bool) config('notifications.panelchi.enabled', true) && trim((string) config('notifications.panelchi.api_key', '')) !== ''
            : (bool) config('customer.ippanel.enabled');
        if (! $providerReady) {
            return false;
        }

        $pattern = trim((string) config('notifications.sms.patterns.'.$patternKey, ''));
        if ($pattern !== '') {
            return true;
        }

        if ($fallbackEvent !== null && (bool) config('notifications.sms.admin_shared_pattern_fallback', false)) {
            return $this->patternSms->resolvePattern($fallbackEvent) !== '';
        }

        return false;
    }

    /**
     * @param  array<string, scalar>  $params
     * @param  array<string, scalar>  $fallbackParams
     */
    private function sendAdminSms(
        string $patternKey,
        array $params,
        ?string $dedupeIdentity = null,
        ?string $fallbackEvent = null,
        array $fallbackParams = [],
    ): void {
        $phones = $this->adminPhones();
        if ($phones === []) {
            return;
        }
        if (! $this->isAdminSmsEnabled()) {
            if (! app()->environment('testing')) {
                Log::notice('Admin SMS skipped because SMS notifications are disabled', ['pattern_key' => $patternKey]);
            }

            return;
        }

        $explicitPattern = trim((string) config('notifications.sms.patterns.'.$patternKey, ''));
        $activePattern = $explicitPattern;
        $activeParams = $params;
        if ($activePattern === '' && $fallbackEvent !== null && (bool) config('notifications.sms.admin_shared_pattern_fallback', false)) {
            $activePattern = $this->patternSms->resolvePattern($fallbackEvent);
            $activeParams = $fallbackParams !== [] ? $fallbackParams : $params;
        }

        if ($activePattern === '') {
            if (! app()->environment('testing')) {
                Log::warning('Admin SMS skipped because pattern code is not configured', ['pattern_key' => $patternKey]);
            }

            return;
        }

        foreach ($phones as $phone) {
            if ($dedupeIdentity !== null && $dedupeIdentity !== '') {
                $cacheKey = 'novinpay:admin_sms:'.hash('sha256', $patternKey.':'.$phone.':'.$dedupeIdentity);
                try {
                    if (! Cache::add($cacheKey, true, now()->addDays(2))) {
                        continue;
                    }
                } catch (\Throwable) {
                }
            }
            $this->patternSms->sendExplicitPattern($patternKey, $phone, $activePattern, $activeParams);
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
    public function dailyDigestEmail(string $dateKey, array $fields, bool $hasAttentionItems = false): bool
    {
        $emails = $this->adminEmails();
        if ($emails === [] || ! config('notifications.email.enabled')) {
            return false;
        }

        $dedupeKey = 'novinpay:daily_digest_email:'.$dateKey;
        try {
            if (! Cache::add($dedupeKey, true, now()->addHours(20))) {
                return false;
            }
        } catch (\Throwable) {
        }

        $delivered = false;
        foreach ($emails as $email) {
            if ($this->sendMail(
                recipient: $email,
                subject: '📊 گزارش جمع‌بندی روزانه نوین‌پی — '.$dateKey,
                heading: 'گزارش جمع‌بندی ۲۴ ساعته عملیات نوین‌پی',
                intro: 'خلاصهٔ وضعیت پرداخت‌ها، سفارش‌ها، تعمیرات، مشاوره‌ها، پیامک‌ها و سلامت زیرساخت برای تاریخ '.$dateKey.' در جدول زیر ثبت شده است.',
                fields: $fields,
                actionLabel: 'مشاهده داشبورد مدیریت',
                actionUrl: url('/admin'),
                statusBadge: $hasAttentionItems ? 'گزارش روزانه · نیازمند بررسی' : 'گزارش روزانه',
                statusTone: $hasAttentionItems ? 'warning' : 'success',
            )) {
                $delivered = true;
            }
        }

        return $delivered;
    }

    /**
     * @param  array<string, string>  $fields
     */
    public function criticalOperationalAlert(
        string $category,
        string $subject,
        string $heading,
        string $intro,
        array $fields,
        string $dedupeKey,
        int $ttlMinutes = 30,
        ?string $actionLabel = 'بررسی وضعیت سامانه',
        ?string $actionUrl = null,
        string $statusBadge = 'هشدار عملیاتی',
        string $statusTone = 'danger',
    ): void {
        $this->safe(function () use ($category, $subject, $heading, $intro, $fields, $dedupeKey, $ttlMinutes, $actionLabel, $actionUrl, $statusBadge, $statusTone): void {
            $emails = $this->adminEmails();
            if ($emails === [] || ! config('notifications.email.enabled')) {
                return;
            }

            if ($dedupeKey !== '') {
                $cacheKey = 'novinpay:ops_alert_email:'.hash('sha256', $category.':'.$dedupeKey);
                try {
                    if (! Cache::add($cacheKey, true, now()->addMinutes(max(1, $ttlMinutes)))) {
                        return;
                    }
                } catch (\Throwable) {
                }
            }

            $enriched = $fields + ['زمان ثبت هشدار (تهران)' => $this->tehranTimestamp()];
            $this->sendAdminEmail(
                subject: $subject,
                heading: $heading,
                intro: $intro,
                fields: $enriched,
                actionLabel: $actionLabel,
                actionUrl: $actionUrl ?? url('/admin/system-health'),
                statusBadge: $statusBadge,
                statusTone: $statusTone,
            );
        }, 'critical_operational_alert_'.$category);
    }

    private function tehranTimestamp(): string
    {
        return FaNumber::digits(now('Asia/Tehran')->format('Y/m/d H:i'));
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
        ?string $statusBadge = null,
        string $statusTone = 'info',
    ): void {
        $emails = $this->adminEmails();
        if ($emails === []) {
            return;
        }
        if (! config('notifications.email.enabled')) {
            if (! app()->environment('testing')) {
                Log::notice('Admin email skipped because NOTIFICATIONS_EMAIL_ENABLED is false');
            }

            return;
        }
        if (app()->environment('production') && in_array((string) config('mail.default'), ['log', 'array'], true)) {
            Log::warning('Admin email dispatched while MAIL_MAILER is non-SMTP in production', [
                'mailer' => config('mail.default'),
            ]);
        }
        foreach ($emails as $email) {
            $this->sendMail($email, $subject, $heading, $intro, $fields, $actionLabel, $actionUrl, $statusBadge, $statusTone);
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
        ?string $statusBadge = null,
        string $statusTone = 'info',
    ): void {
        if (! config('notifications.email.enabled')) {
            return;
        }
        $clean = trim((string) $email);
        if ($clean === '' || ! filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $this->sendMail($clean, $subject, $heading, $intro, $fields, $actionLabel, $actionUrl, $statusBadge, $statusTone);
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
        ?string $statusBadge = null,
        string $statusTone = 'info',
    ): bool {
        try {
            Mail::to($recipient)->send(new UnifiedNotificationMail(
                mailSubject: $subject,
                heading: $heading,
                intro: $intro,
                fields: $fields,
                actionLabel: $actionLabel,
                actionUrl: $actionUrl,
                statusBadge: $statusBadge,
                statusTone: $statusTone,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Notification email dispatch failed', ['exception' => $e::class]);

            return false;
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
