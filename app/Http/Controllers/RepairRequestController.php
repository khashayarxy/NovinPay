<?php

namespace App\Http\Controllers;

use App\Enums\RepairBrand;
use App\Enums\RepairRequestStatus;
use App\Http\Requests\StoreRepairRequest;
use App\Http\Requests\TrackRepairRequest;
use App\Models\RepairRequest;
use App\Support\RepairIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RepairRequestController extends Controller
{
    public function create(Request $request): View
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
        $repair->tracking_reference = 'NP-'.strtoupper(bin2hex(random_bytes(16)));
        $repair->submission_digest = RepairIdentity::digest('submission', $issuedToken);
        $repair->customer_name = $data['customer_name'];
        $repair->contact_phone = $data['contact_phone'];
        $repair->phone_digest = RepairIdentity::digest('phone', $data['contact_phone']);
        $repair->device_brand = RepairBrand::from($data['device_brand']);
        $repair->device_model = $data['device_model'];
        $repair->issue = $data['issue'];
        $repair->status = RepairRequestStatus::OnlineSubmitted;

        try {
            // The unique submission digest also prevents concurrent double submits.
            $repair->save();
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
        $repair = RepairRequest::query()->select(['phone_digest', 'status'])
            ->where('tracking_reference', $data['tracking_reference'])->first();
        $matched = hash_equals(
            $repair?->phone_digest ?? str_repeat('0', 64),
            RepairIdentity::digest('phone', $data['contact_phone']),
        );

        // Only the explicitly supported initial state can be exposed in Phase 4.
        return view('pages.repair-tracking', [
            'matched' => $matched && $repair?->status === RepairRequestStatus::OnlineSubmitted,
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
