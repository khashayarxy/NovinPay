<?php

namespace App\Http\Controllers;

use App\Enums\RepairBrand;
use App\Enums\RepairRequestStatus;
use App\Http\Requests\StoreRepairRequest;
use App\Http\Requests\TrackRepairRequest;
use App\Models\CustomerAccount;
use App\Models\DeliveryPlan;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Services\CustomerLogin;
use App\Services\CustomerNotifications;
use App\Support\CustomerTimeline;
use App\Support\RepairIdentity;
use App\Support\RepairTracking;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

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
        $repair->status = RepairRequestStatus::OnlineSubmitted;
        $account = Auth::guard('customer')->user();
        try {
            // The unique submission digest also prevents concurrent double submits.
            DB::transaction(function () use ($repair, $account, $request): void {
                // A reset and a new request serialize on the same owner row.
                $owner = CustomerAccount::query()->lockForUpdate()->find($account->id);
                abort_unless($owner?->is_active && $owner->session_version === $request->session()->get('customer_session_version')
                    && ($owner->verified_at || app(CustomerLogin::class)->isTestAccount($owner)), 403);
                $repair->customer_account_id = $owner->id;
                $repair->customer_id = $owner->customer_id;
                $repair->tracking_reference = RepairTracking::reserve();
                $repair->save();
                if ($owner->verified_at) {
                    app(CustomerNotifications::class)->record($owner->id, 'repair_registered', $repair->tracking_reference);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateResponse($request);
        }

        $request->session()->forget('repair_submission_token');

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

    public function tracking(): View
    {
        return view('pages.repair-tracking', ['matched' => null]);
    }

    public function track(TrackRepairRequest $request): View
    {
        $data = $request->validated();
        // Never hydrate or decrypt customer/device fields for a tracking response.
        $physical = RepairCase::query()->select(['id', 'phone_digest', 'status', 'delivery_method'])
            ->where(fn ($query) => $query->where('tracking_digest', RepairIdentity::digest('tracking', $data['tracking_reference']))
                ->orWhereHas('onlineRequest', fn ($source) => $source->where('tracking_reference', $data['tracking_reference'])))->first();
        $physicalMatch = hash_equals($physical?->phone_digest ?? str_repeat('0', 64), RepairIdentity::digest('phone', $data['contact_phone']));
        // Only retrieve a public state after the tracking code AND phone match.
        $plan = $physicalMatch ? DeliveryPlan::query()->select('state')->whereIn('financial_document_id',
            DB::table('financial_documents')->select('id')->where('repair_case_id', $physical->id))->first() : null;
        $repair = RepairRequest::query()->select(['phone_digest', 'status'])
            ->where('tracking_reference', $data['tracking_reference'])->first();
        $matched = hash_equals(
            $repair?->phone_digest ?? str_repeat('0', 64),
            RepairIdentity::digest('phone', $data['contact_phone']),
        );

        return view('pages.repair-tracking', [
            'matched' => $physicalMatch || ($matched && $repair?->status === RepairRequestStatus::OnlineSubmitted),
            'physicalMatch' => $physicalMatch,
            'physicalStatus' => $physicalMatch ? CustomerTimeline::repairStatus($physical, $plan) : null,
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
