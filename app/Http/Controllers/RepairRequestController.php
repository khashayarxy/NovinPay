<?php

namespace App\Http\Controllers;

use App\Enums\RepairBrand;
use App\Enums\RepairRequestStatus;
use App\Enums\RepairStage;
use App\Http\Requests\StoreRepairRequest;
use App\Http\Requests\TrackRepairRequest;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Services\CustomerLogin;
use App\Services\CustomerNotifications;
use App\Services\LegacyCartHold;
use App\Services\Notifications\UnifiedNotifier;
use App\Support\CustomerTimeline;
use App\Support\FaNumber;
use App\Support\FinanceTime;
use App\Support\RepairIdentity;
use App\Support\RepairPhotoProcessor;
use App\Support\RepairTracking;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RepairRequestController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('repair_submission_token')) {
            $request->session()->put('repair_submission_token', bin2hex(random_bytes(32)));
        }

        $authExpiresAt = (int) $request->session()->get('repair_auth_expires_at', 0);
        if ($authExpiresAt > 0 && now()->timestamp > $authExpiresAt && $request->session()->has('repair_auth_challenge')) {
            $request->session()->forget(['repair_auth_challenge', 'repair_auth_expires_at']);
        }

        return view('pages.repair', [
            'brands' => RepairBrand::cases(),
            'submissionToken' => $request->session()->get('repair_submission_token'),
            'iranLocations' => config('iran_locations', []),
            'courierZones' => config('delivery.courier_zones', []),
            'neshanWebKey' => (string) config('services.neshan.web_key', ''),
            'authChallenge' => $request->session()->has('repair_auth_challenge'),
            'authDisplayPhone' => $request->session()->get('repair_auth_phone_display'),
            'authResendAt' => (int) $request->session()->get('repair_auth_resend_at', 0),
        ]);
    }

    public function sendAuth(Request $request, CustomerLogin $login): RedirectResponse|JsonResponse
    {
        $rawPhone = $request->input('contact_phone');
        if ((! is_string($rawPhone) || trim($rawPhone) === '') && is_string($enc = $request->session()->get('repair_auth_phone_enc'))) {
            try {
                $rawPhone = Crypt::decryptString($enc);
            } catch (\Throwable) {
                $rawPhone = '';
            }
        }

        $phone = RepairIdentity::phone($rawPhone);
        if (! preg_match('/\A09[0-9]{9}\z/D', $phone)) {
            $message = 'شماره موبایل ۱۱ رقمی معتبر با ۰۹ وارد کنید.';
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message, 'errors' => ['contact_phone' => [$message]]], 422);
            }

            return redirect()->route('repair')->withErrors(['contact_phone' => $message]);
        }

        try {
            $login->issue($phone, fn ($challenge) => $request->session()->put('repair_auth_challenge', $challenge));
            $resendAt = now()->addSeconds(CustomerLogin::RESEND_SECONDS)->timestamp;
            $displayPhone = FaNumber::digits($phone);
            $request->session()->put([
                'repair_auth_phone_enc' => Crypt::encryptString($phone),
                'repair_auth_phone_display' => $displayPhone,
                'repair_auth_resend_at' => $resendAt,
                'repair_auth_expires_at' => now()->addMinutes(2)->timestamp,
            ]);
        } catch (HttpException $error) {
            $retryAfter = (int) ($error->getHeaders()['Retry-After'] ?? CustomerLogin::RESEND_SECONDS);
            if ($error->getStatusCode() === 429) {
                $request->session()->put('repair_auth_resend_at', now()->addSeconds(max(1, $retryAfter))->timestamp);
            }
            $message = $error->getStatusCode() === 429
                ? 'کمی صبر کنید و دوباره کد تأیید بخواهید.'
                : 'ارسال کد تأیید در حال حاضر ممکن نیست. کمی بعد دوباره تلاش کنید.';
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                    'resend_at' => (int) $request->session()->get('repair_auth_resend_at', 0),
                ], $error->getStatusCode());
            }

            return redirect()->route('repair')->withErrors(['contact_phone' => $message]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'phone' => $displayPhone,
                'resend_at' => $resendAt,
                'remaining' => max(0, $resendAt - now()->timestamp),
            ]);
        }

        return redirect()->route('repair');
    }

    public function verifyAuth(Request $request, CustomerLogin $login): RedirectResponse|JsonResponse
    {
        $input = $request->input('code');
        $normalized = is_string($input) ? FaNumber::normalizeDigits($input) : '';
        $code = preg_replace('/\D+/', '', $normalized) ?? '';
        $challenge = $request->session()->get('repair_auth_challenge');
        if (! is_string($challenge) || ! preg_match('/\A[0-9]{6}\z/D', $code)) {
            $message = 'کد تأیید شش‌رقمی را وارد کنید.';
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message, 'errors' => ['code' => [$message]]], 422);
            }

            return redirect()->route('repair')->withErrors(['code' => $message]);
        }

        try {
            $account = $login->verify($challenge, $code);
        } catch (ValidationException $error) {
            if ($request->expectsJson()) {
                $first = collect($error->errors())->flatten()->first() ?: 'کد تأیید نادرست یا منقضی است.';

                return response()->json(['ok' => false, 'message' => $first, 'errors' => $error->errors()], 422);
            }

            return redirect()->route('repair')->withErrors($error->errors());
        }

        if (! $account->is_active) {
            $message = 'حساب کاربری غیرفعال است.';
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $message], 403);
            }

            return redirect()->route('repair')->withErrors(['code' => $message]);
        }

        app(LegacyCartHold::class)->rememberOriginalSession($request);
        Auth::guard('customer')->login($account);
        $request->session()->regenerate(true);
        $request->session()->forget([
            'repair_auth_challenge',
            'repair_auth_phone_enc',
            'repair_auth_phone_display',
            'repair_auth_resend_at',
            'repair_auth_expires_at',
        ]);
        $request->session()->put('customer_session_version', $account->session_version);
        $request->session()->put('customer_otp_at', now()->timestamp);
        if (! $request->session()->has('repair_submission_token')) {
            $request->session()->put('repair_submission_token', bin2hex(random_bytes(32)));
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'redirect' => route('repair'),
                'csrf_token' => csrf_token(),
                'submission_token' => $request->session()->get('repair_submission_token'),
                'phone' => FaNumber::digits($account->phone),
                'customer_name' => $account->name,
            ]);
        }

        return redirect()->route('repair');
    }

    public function resetAuth(Request $request): RedirectResponse|JsonResponse
    {
        $request->session()->forget([
            'repair_auth_challenge',
            'repair_auth_phone_enc',
            'repair_auth_phone_display',
            'repair_auth_resend_at',
            'repair_auth_expires_at',
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('repair');
    }

    public function start(): RedirectResponse
    {
        return redirect()->route('repair');
    }

    public function store(StoreRepairRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $issuedToken = $request->session()->get('repair_submission_token');
        if (! is_string($issuedToken) || ! hash_equals($issuedToken, $data['submission_token'])) {
            return $this->duplicateResponse($request);
        }

        $repair = new RepairRequest;
        $repair->submission_digest = RepairIdentity::digest('submission', $issuedToken);
        $repair->customer_name = $data['customer_name'];
        $repair->contact_phone = $data['contact_phone'];
        $repair->phone_digest = RepairIdentity::digest('phone', $data['contact_phone']);
        $repair->device_brand = RepairBrand::from($data['device_brand']);
        $repair->device_model = $data['device_model'];
        $repair->issue = $data['issue'].(! empty($data['additional_description']) ? "\n\n".$data['additional_description'] : '');
        if (! empty($data['device_serial'])) {
            $repair->issue .= "\n\nشماره سریال: ".$data['device_serial'];
        }
        if (! empty($data['accessories'])) {
            $repair->issue .= "\nلوازم همراه: ".implode('، ', array_map(fn ($accessory) => RepairRequest::ACCESSORIES[$accessory], $data['accessories']));
        }
        $locationParts = array_filter([
            trim((string) ($data['province'] ?? '')),
            trim((string) ($data['city'] ?? '')),
        ]);
        if ($locationParts !== []) {
            $repair->issue .= "\nاستان و شهر: ".implode(' - ', $locationParts);
        }
        if (! empty($data['handover_method'])) {
            $repair->issue .= "\nنحوه تحویل دستگاه: ".RepairRequest::HANDOVER_METHODS[$data['handover_method']];
        }
        if (($data['handover_method'] ?? null) === 'doorstep_pickup') {
            if (! empty($data['pickup_address'])) {
                $repair->issue .= "\nنشانی تحویل در محل: ".trim($data['pickup_address']);
            }
            if (isset($data['pickup_latitude'], $data['pickup_longitude']) && $data['pickup_latitude'] !== '' && $data['pickup_longitude'] !== '') {
                $repair->issue .= "\nموقعیت نقشه: ".number_format((float) $data['pickup_latitude'], 6, '.', '').', '.number_format((float) $data['pickup_longitude'], 6, '.', '');
            }
            if (! empty($data['responsive_phone'])) {
                $repair->issue .= "\nشماره تماس پاسخگو: ".$data['responsive_phone'];
            }
        }
        $repair->status = RepairRequestStatus::OnlineSubmitted;
        $account = Auth::guard('customer')->user();
        $storedPaths = [];
        try {
            // The unique submission digest also prevents concurrent double submits.
            DB::transaction(function () use ($repair, $account, $request, &$storedPaths): void {
                // A reset and a new request serialize on the same owner row.
                $owner = CustomerAccount::query()->lockForUpdate()->find($account->id);
                abort_unless($owner?->is_active && $owner->session_version === $request->session()->get('customer_session_version')
                    && ($owner->verified_at || app(CustomerLogin::class)->isTestAccount($owner)), 403);
                $repair->customer_account_id = $owner->id;
                $repair->customer_id = $owner->customer_id;
                $repair->tracking_reference = RepairTracking::reserve();
                $repair->save();
                if ($request->hasFile('device_photo')) {
                    $photoPath = $repair->photoPath();
                    $compressed = RepairPhotoProcessor::compress($request->file('device_photo'));
                    abort_unless($compressed['bytes'] !== '' && Storage::disk('local')->put($photoPath, $compressed['bytes']), 503);
                    $storedPaths[] = $photoPath;
                }
                foreach (array_keys(RepairRequest::PHOTO_SIDES) as $side) {
                    if ($request->hasFile("device_photos.{$side}")) {
                        $compressed = RepairPhotoProcessor::compress($request->file("device_photos.{$side}"));
                        $sidePath = $repair->sidePhotoPath($side, $compressed['extension']);
                        abort_unless($compressed['bytes'] !== '' && Storage::disk('local')->put($sidePath, $compressed['bytes']), 503);
                        $storedPaths[] = $sidePath;
                    }
                }
                if ($owner->verified_at) {
                    app(CustomerNotifications::class)->record($owner->id, 'repair_registered', $repair->tracking_reference);
                }
            });
        } catch (\Throwable $error) {
            foreach ($storedPaths as $writtenPath) {
                Storage::disk('local')->delete($writtenPath);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                return $this->duplicateResponse($request);
            }

            throw $error;
        }

        $request->session()->forget('repair_submission_token');
        app(UnifiedNotifier::class)->repairRequestSubmitted($repair);

        return redirect()->route('repair.confirmation')
            ->with('repair_reference', $repair->tracking_reference)
            ->with('repair_handover', $data['handover_method'] ?? null);
    }

    public function confirmation(Request $request): View|RedirectResponse
    {
        $reference = $request->session()->get('repair_reference');

        return is_string($reference)
            ? view('pages.repair-confirmation', ['reference' => $reference])
            : redirect()->route('repair');
    }

    public function tracking(Request $request, ?string $code = null): View
    {
        $digest = $this->recoveredPhone($request);

        return $code && $digest
            ? $this->trackingResult($code, $digest)
            : view('pages.repair-tracking', ['matched' => null, 'trackingCode' => $code]);
    }

    public function recovery(Request $request): View
    {
        if ($request->boolean('edit')) {
            $request->session()->forget([
                'repair_recovery_challenge',
                'repair_recovery_phone_enc',
                'repair_recovery_phone_masked',
                'repair_recovery_resend_at',
                'repair_recovery_expires_at',
            ]);
        }

        $expiresAt = (int) $request->session()->get('repair_recovery_expires_at', 0);
        if ($expiresAt > 0 && now()->timestamp > $expiresAt && $request->session()->has('repair_recovery_challenge')) {
            $request->session()->forget(['repair_recovery_challenge', 'repair_recovery_expires_at']);
        }

        $digest = $this->recoveredPhone($request);
        $recoveredItems = collect();
        if ($digest) {
            $byCode = [];
            $cases = RepairCase::query()
                ->where('phone_digest', $digest)
                ->whereNotNull('tracking_reference')
                ->latest('created_at')
                ->get(['tracking_reference', 'device_brand', 'device_model', 'received_at', 'created_at']);
            foreach ($cases as $case) {
                $code = (string) $case->tracking_reference;
                if (! preg_match('/\A[A-Za-z0-9-]{1,35}\z/D', $code) || isset($byCode[$code])) {
                    continue;
                }
                $device = RepairIdentity::deviceLabel($case->device_brand, $case->device_model);
                $ts = $case->received_at ?? $case->created_at;
                $byCode[$code] = [
                    'code' => $code,
                    'device' => $device,
                    'isLatinDevice' => preg_match('/[A-Za-z]/', $device) === 1,
                    'date' => FinanceTime::display($ts, false),
                    'sortAt' => $ts?->getTimestamp() ?? 0,
                ];
            }

            $onlineRequests = RepairRequest::query()
                ->where('phone_digest', $digest)
                ->whereNotNull('tracking_reference')
                ->latest('created_at')
                ->get(['tracking_reference', 'device_brand', 'device_model', 'created_at']);
            foreach ($onlineRequests as $req) {
                $code = (string) $req->tracking_reference;
                if (! preg_match('/\A[A-Za-z0-9-]{1,35}\z/D', $code)) {
                    continue;
                }
                if (isset($byCode[$code])) {
                    if ($req->created_at) {
                        $byCode[$code]['date'] = FinanceTime::display($req->created_at, false);
                        $byCode[$code]['sortAt'] = $req->created_at->getTimestamp();
                    }

                    continue;
                }
                $device = RepairIdentity::deviceLabel($req->device_brand, $req->device_model);
                $byCode[$code] = [
                    'code' => $code,
                    'device' => $device,
                    'isLatinDevice' => preg_match('/[A-Za-z]/', $device) === 1,
                    'date' => FinanceTime::display($req->created_at, false),
                    'sortAt' => $req->created_at?->getTimestamp() ?? 0,
                ];
            }

            $recoveredItems = collect($byCode)
                ->sort(fn (array $a, array $b): int => ($b['sortAt'] <=> $a['sortAt']) ?: strcmp($a['code'], $b['code']))
                ->values()
                ->map(fn (array $item): array => [
                    'code' => $item['code'],
                    'device' => $item['device'],
                    'isLatinDevice' => $item['isLatinDevice'],
                    'date' => $item['date'],
                ]);
        }

        return view('pages.repair-recovery', [
            'codes' => $recoveredItems->pluck('code')->values(),
            'recoveredItems' => $recoveredItems,
            'verified' => $digest !== null,
            'challenge' => $request->session()->has('repair_recovery_challenge'),
            'maskedPhone' => $request->session()->get('repair_recovery_phone_masked'),
            'resendAt' => (int) $request->session()->get('repair_recovery_resend_at', 0),
        ]);
    }

    public function recoveredTracking(Request $request, string $reference): View|RedirectResponse
    {
        $digest = $this->recoveredPhone($request);

        return $digest ? $this->trackingResult($reference, $digest) : redirect()->route('repair.track.recovery');
    }

    public function sendRecovery(Request $request, CustomerLogin $login): RedirectResponse
    {
        $rawPhone = $request->input('contact_phone');
        if ((! is_string($rawPhone) || trim($rawPhone) === '') && is_string($enc = $request->session()->get('repair_recovery_phone_enc'))) {
            try {
                $rawPhone = Crypt::decryptString($enc);
            } catch (\Throwable) {
                $rawPhone = '';
            }
        }
        $phone = RepairIdentity::phone($rawPhone);
        if (! preg_match('/\A09[0-9]{9}\z/D', $phone)) {
            return redirect()->route('repair.track.recovery')->withErrors(['contact_phone' => 'شماره موبایل معتبر وارد کنید.']);
        }
        $request->session()->forget('repair_recovery_phone');
        try {
            $login->issue($phone, fn ($challenge) => $request->session()->put('repair_recovery_challenge', $challenge));
            $request->session()->put([
                'repair_recovery_phone_enc' => Crypt::encryptString($phone),
                'repair_recovery_phone_masked' => FaNumber::digits(substr($phone, 0, 4).'***'.substr($phone, -4)),
                'repair_recovery_resend_at' => now()->addSeconds(CustomerLogin::RESEND_SECONDS)->timestamp,
                'repair_recovery_expires_at' => now()->addMinutes(2)->timestamp,
            ]);
        } catch (HttpException $error) {
            $retryAfter = (int) ($error->getHeaders()['Retry-After'] ?? CustomerLogin::RESEND_SECONDS);
            if ($error->getStatusCode() === 429) {
                $request->session()->put('repair_recovery_resend_at', now()->addSeconds(max(1, $retryAfter))->timestamp);
            }

            return redirect()->route('repair.track.recovery')->withErrors(['contact_phone' => $error->getStatusCode() === 429
                ? 'کمی صبر کنید و دوباره کد بخواهید.' : 'ارسال کد ممکن نیست. کمی بعد دوباره تلاش کنید.']);
        }

        return redirect()->route('repair.track.recovery');
    }

    public function resetRecovery(Request $request): RedirectResponse
    {
        $request->session()->forget([
            'repair_recovery_challenge',
            'repair_recovery_phone_enc',
            'repair_recovery_phone_masked',
            'repair_recovery_resend_at',
            'repair_recovery_expires_at',
        ]);

        return redirect()->route('repair.track.recovery');
    }

    public function verifyRecovery(Request $request, CustomerLogin $login): RedirectResponse
    {
        $input = $request->input('code');
        $normalized = is_string($input) ? FaNumber::normalizeDigits($input) : '';
        $code = preg_replace('/\D+/', '', $normalized) ?? '';
        $challenge = $request->session()->get('repair_recovery_challenge');
        if (! is_string($challenge) || ! preg_match('/\A[0-9]{6}\z/D', $code)) {
            return redirect()->route('repair.track.recovery')->withErrors(['code' => 'کد تأیید شش‌رقمی را وارد کنید.']);
        }

        try {
            $account = $login->verify($challenge, $code);
        } catch (ValidationException $error) {
            return redirect()->route('repair.track.recovery')->withErrors($error->errors());
        }

        $request->session()->regenerate(true);
        $request->session()->forget([
            'repair_recovery_challenge',
            'repair_recovery_phone_enc',
            'repair_recovery_phone_masked',
            'repair_recovery_resend_at',
            'repair_recovery_expires_at',
        ]);
        $request->session()->put('repair_recovery_phone', [
            'digest' => RepairIdentity::digest('phone', $account->phone),
            'expires' => now()->addMinutes(15)->timestamp,
        ]);

        return redirect()->route('repair.track.recovery');
    }

    private function recoveredPhone(Request $request): ?string
    {
        $grant = $request->session()->get('repair_recovery_phone');

        return is_array($grant) && ($grant['expires'] ?? 0) > now()->timestamp ? $grant['digest'] : null;
    }

    public function track(TrackRepairRequest $request): View
    {
        $data = $request->validated();

        return $this->trackingResult($data['tracking_reference'], RepairIdentity::digest('phone', $data['contact_phone']));
    }

    private function trackingResult(string $reference, string $phoneDigest): View
    {
        // Only retrieve minimal non-PII summary fields after both tracking code AND phone match.
        $physical = RepairCase::query()->select(['id', 'phone_digest', 'status', 'delivery_method', 'simple_workflow', 'device_brand', 'device_model', 'repair_request_id'])
            ->where(fn ($query) => $query->where('tracking_digest', RepairIdentity::digest('tracking', $reference))
                ->orWhereHas('onlineRequest', fn ($source) => $source->where('tracking_reference', $reference)))->first();
        $physicalMatch = hash_equals($physical?->phone_digest ?? str_repeat('0', 64), $phoneDigest);
        $plan = $physicalMatch ? DeliveryPlan::query()->select('state')->whereIn('financial_document_id',
            DB::table('financial_documents')->select('id')->where('repair_case_id', $physical->id))->first() : null;
        $repair = RepairRequest::query()->select(['id', 'phone_digest', 'status', 'device_brand', 'device_model', 'issue', 'created_at'])
            ->where('tracking_reference', $reference)->first();
        $matched = hash_equals(
            $repair?->phone_digest ?? str_repeat('0', 64),
            $phoneDigest,
        );

        $isMatched = $physicalMatch || ($matched && $repair?->status === RepairRequestStatus::OnlineSubmitted);
        $trackSummary = null;
        if ($isMatched) {
            $sourceBrand = $physicalMatch ? ($physical->device_brand ?? $repair?->device_brand) : $repair?->device_brand;
            $sourceModel = $physicalMatch ? ($physical->device_model ?: $repair?->device_model) : $repair?->device_model;
            $deliveryLabel = null;
            if ($repair && $matched) {
                $badge = $repair->handoverBadge();
                if ($badge) {
                    $deliveryLabel = match ($badge['label']) {
                        'حضوری' => 'خودم حضوری میارم',
                        'پستی' => 'خودم ارسال میکنم',
                        'پیک' => 'به پیک شما تحویل میدم',
                        default => $badge['label'],
                    };
                }
            }
            if ($deliveryLabel === null && $physicalMatch) {
                $deliveryLabel = match ($physical->delivery_method) {
                    'shipping' => 'ارسال پستی',
                    'pickup', 'in_person' => 'تحویل حضوری',
                    default => 'ثبت‌شده در پذیرش',
                };
            }

            $trackSummary = [
                'code' => FaNumber::digits($reference),
                'device' => RepairIdentity::deviceLabel($sourceBrand, $sourceModel),
                'delivery' => $deliveryLabel ?? 'ثبت‌شده',
            ];
        }

        $timeline = $physicalMatch
            ? CustomerTimeline::repair($physical, $plan, public: true)
            : ($isMatched ? [
                ['label' => 'ثبت درخواست', 'state' => 'complete', 'at' => $repair?->created_at],
                ['label' => 'دریافت دستگاه', 'state' => 'current', 'note' => 'در انتظار تحویل دستگاه', 'at' => null],
                ['label' => 'بررسی و اعلام هزینه', 'state' => 'upcoming', 'at' => null],
                ['label' => 'تعمیر و تحویل', 'state' => 'upcoming', 'at' => null],
            ] : []);

        return view('pages.repair-tracking', [
            'matched' => $isMatched,
            'physicalMatch' => $physicalMatch,
            'physicalStatus' => $physicalMatch ? CustomerTimeline::repairStatus($physical, $plan) : null,
            'trackSummary' => $trackSummary,
            'nextAction' => $physicalMatch ? match ($physical->status) {
                RepairStage::WaitingApproval => 'برای بررسی و تأیید برآورد، جزئیات تعمیر را در حساب کاربری باز کنید.',
                RepairStage::Ready, RepairStage::ReturnPending => 'برای تحویل دستگاه، با نوین‌پی هماهنگ کنید.',
                RepairStage::Delivered, RepairStage::Returned => 'برای جزئیات تحویل، پرونده را در حساب کاربری باز کنید.',
                default => 'آخرین مرحلهٔ بررسی و تعمیر را در همین صفحه دنبال کنید.',
            } : 'کد پیگیری را برای تحویل حضوری یا ارسال دستگاه همراه داشته باشید.',
            'timeline' => $timeline,
        ]);
    }

    private function duplicateResponse(Request $request): RedirectResponse
    {
        // A stale concurrent session must not keep issuing an already-used token.
        $request->session()->forget('repair_submission_token');

        return redirect()->route('repair')->withErrors([
            'submission_token' => 'این فرم قبلاً پردازش شده یا منقضی است. اگر کد دارید از پیگیری استفاده کنید؛ برای درخواست تازه فرم را دوباره باز کنید.',
        ]);
    }
}
