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
use App\Services\Notifications\UnifiedNotifier;
use App\Support\CustomerTimeline;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use App\Support\RepairTracking;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return view('pages.repair', [
            'brands' => RepairBrand::cases(),
            'submissionToken' => $request->session()->get('repair_submission_token'),
        ]);
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
        $repair->status = RepairRequestStatus::OnlineSubmitted;
        $account = Auth::guard('customer')->user();
        $photoPath = null;
        try {
            // The unique submission digest also prevents concurrent double submits.
            DB::transaction(function () use ($repair, $account, $request, &$photoPath): void {
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
                    abort_unless($request->file('device_photo')->storeAs(dirname($photoPath), basename($photoPath), 'local'), 503);
                }
                if ($owner->verified_at) {
                    app(CustomerNotifications::class)->record($owner->id, 'repair_registered', $repair->tracking_reference);
                }
            });
        } catch (\Throwable $error) {
            if ($photoPath !== null) {
                Storage::disk('local')->delete($photoPath);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                return $this->duplicateResponse($request);
            }

            throw $error;
        }

        $request->session()->forget('repair_submission_token');
        app(UnifiedNotifier::class)->repairRequestSubmitted($repair);

        return redirect()->route('repair.confirmation')
            ->with('repair_reference', $repair->tracking_reference);
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
        $digest = $this->recoveredPhone($request);
        $codes = collect();
        if ($digest) {
            $online = DB::table('repair_requests')->where('phone_digest', $digest)->select('tracking_reference', 'created_at');
            $physical = DB::table('repair_cases')->where('phone_digest', $digest)->select('tracking_reference', 'created_at');
            $codes = DB::query()->fromSub($online->unionAll($physical), 'repairs')
                ->whereNotNull('tracking_reference')->orderByDesc('created_at')->orderBy('tracking_reference')
                ->pluck('tracking_reference')->filter(fn ($code) => preg_match('/\A[A-Za-z0-9-]{1,35}\z/D', $code))->unique();
        }

        return view('pages.repair-recovery', [
            'codes' => $codes,
            'verified' => $digest !== null,
            'challenge' => $request->session()->has('repair_recovery_challenge'),
        ]);
    }

    public function recoveredTracking(Request $request, string $reference): View|RedirectResponse
    {
        $digest = $this->recoveredPhone($request);

        return $digest ? $this->trackingResult($reference, $digest) : redirect()->route('repair.track.recovery');
    }

    public function sendRecovery(Request $request, CustomerLogin $login): RedirectResponse
    {
        $phone = RepairIdentity::phone($request->input('contact_phone'));
        if (! preg_match('/\A09[0-9]{9}\z/D', $phone)) {
            return redirect()->route('repair.track.recovery')->withErrors(['contact_phone' => 'شماره موبایل معتبر وارد کنید.']);
        }
        $request->session()->forget('repair_recovery_phone');
        try {
            $login->issue($phone, fn ($challenge) => $request->session()->put('repair_recovery_challenge', $challenge));
        } catch (HttpException $error) {
            return redirect()->route('repair.track.recovery')->withErrors(['contact_phone' => $error->getStatusCode() === 429
                ? 'کمی صبر کنید و دوباره کد بخواهید.' : 'ارسال کد ممکن نیست. کمی بعد دوباره تلاش کنید.']);
        }

        return redirect()->route('repair.track.recovery');
    }

    public function verifyRecovery(Request $request, CustomerLogin $login): RedirectResponse
    {
        $input = $request->input('code');
        $code = is_string($input) ? FaNumber::normalizeDigits(trim($input)) : '';
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
        $request->session()->forget('repair_recovery_challenge');
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
        // Never hydrate or decrypt customer/device fields for a tracking response.
        $physical = RepairCase::query()->select(['id', 'phone_digest', 'status', 'delivery_method', 'simple_workflow'])
            ->where(fn ($query) => $query->where('tracking_digest', RepairIdentity::digest('tracking', $reference))
                ->orWhereHas('onlineRequest', fn ($source) => $source->where('tracking_reference', $reference)))->first();
        $physicalMatch = hash_equals($physical?->phone_digest ?? str_repeat('0', 64), $phoneDigest);
        // Only retrieve a public state after the tracking code AND phone match.
        $plan = $physicalMatch ? DeliveryPlan::query()->select('state')->whereIn('financial_document_id',
            DB::table('financial_documents')->select('id')->where('repair_case_id', $physical->id))->first() : null;
        $repair = RepairRequest::query()->select(['phone_digest', 'status'])
            ->where('tracking_reference', $reference)->first();
        $matched = hash_equals(
            $repair?->phone_digest ?? str_repeat('0', 64),
            $phoneDigest,
        );

        return view('pages.repair-tracking', [
            'matched' => $physicalMatch || ($matched && $repair?->status === RepairRequestStatus::OnlineSubmitted),
            'physicalMatch' => $physicalMatch,
            'physicalStatus' => $physicalMatch ? CustomerTimeline::repairStatus($physical, $plan) : null,
            'nextAction' => $physicalMatch ? match ($physical->status) {
                RepairStage::WaitingApproval => 'برای بررسی و تأیید برآورد، جزئیات تعمیر را در حساب کاربری باز کنید.',
                RepairStage::Ready, RepairStage::ReturnPending => 'برای تحویل دستگاه، با نوین‌پی هماهنگ کنید.',
                RepairStage::Delivered, RepairStage::Returned => 'برای جزئیات تحویل، پرونده را در حساب کاربری باز کنید.',
                default => 'آخرین مرحلهٔ بررسی و تعمیر را در همین صفحه دنبال کنید.',
            } : 'کد پیگیری را برای تحویل حضوری یا ارسال دستگاه همراه داشته باشید.',
            'timeline' => $physicalMatch ? CustomerTimeline::repair($physical, $plan, public: true)
                : ($matched ? CustomerTimeline::request($repair) : []),
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
