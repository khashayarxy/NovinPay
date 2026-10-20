<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Enums\RepairBrand;
use App\Enums\RepairStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\RepairOperationRequest;
use App\Models\AdminAccount;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\RepairOperations;
use App\Support\AdminHistory;
use App\Support\RepairIdentity;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RepairCaseController extends Controller
{
    private function technicians(bool $includeHistorical = false)
    {
        $eligible = User::query()->whereIn('id', AdminAccount::query()->where('is_active', true)->select('user_id'))->select('id', 'name')->orderBy('id')->get()->filter(fn ($user) => RepairOperations::eligibleTechnician($user));
        if ($includeHistorical) {
            $historical = User::query()->whereIn('id', RepairCase::query()->whereNotNull('assigned_to')->select('assigned_to'))->select('id', 'name')->get();

            return $eligible->merge($historical)->unique('id')->values();
        }

        return $eligible;
    }

    public function index(Request $request): View
    {
        $criteria = $request->session()->get('repair_search', []);
        $query = RepairCase::query()->with('technician:id,name')->select(['id', 'public_id', 'device_brand', 'status', 'assigned_to', 'received_at', 'repair_request_id']);
        foreach (['status' => 'status', 'brand' => 'device_brand', 'technician' => 'assigned_to'] as $key => $column) {
            if (! empty($criteria[$key])) {
                $query->where($column, $criteria[$key]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $comparison) {
            if (! empty($criteria[$key])) {
                $day = Carbon::parse($criteria[$key], 'Asia/Tehran');
                $query->where('received_at', $comparison, ($key === 'from' ? $day->startOfDay() : $day->endOfDay())->utc());
            }
        }
        if (! empty($criteria['search_column'])) {
            if (! in_array($criteria['search_column'], ['phone_digest', 'serial_digest'], true) || Gate::allows('repairs.customer_data')) {
                $query->where($criteria['search_column'], $criteria['search_value']);
            }
        }

        return view('admin.repair-cases.index', ['cases' => $query->latest('id')->paginate(20), 'criteria' => $criteria, 'technicians' => $this->technicians(true), 'stages' => RepairStage::cases(), 'brands' => RepairBrand::cases(), 'counts' => ['all' => RepairCase::query()->count(), 'open' => RepairCase::query()->whereNotIn('status', ['delivered', 'returned'])->count(), 'approval' => RepairCase::query()->where('status', 'waiting_approval')->count(), 'ready' => RepairCase::query()->where('status', 'ready')->count()]]);
    }

    public function search(RepairOperationRequest $request): RedirectResponse
    {
        $criteria = $request->safe()->except('q');
        $q = strtoupper(trim($request->validated('q') ?? ''));
        if ($q !== '') {
            if (preg_match('/\ANP-[A-F0-9]{32}\z/', $q)) {
                $criteria += ['search_column' => 'tracking_digest', 'search_value' => RepairIdentity::digest('tracking', $q)];
            } elseif (preg_match('/\A[0-9A-F]{8}-(?:[0-9A-F]{4}-){3}[0-9A-F]{12}\z/', $q)) {
                $criteria += ['search_column' => 'public_id', 'search_value' => strtolower($q)];
            } else {
                Gate::authorize('repairs.customer_data');
                $phone = RepairIdentity::phone($q);
                $isPhone = preg_match('/\A09[0-9]{9}\z/', $phone);
                $criteria += ['search_column' => $isPhone ? 'phone_digest' : 'serial_digest', 'search_value' => RepairIdentity::digest($isPhone ? 'phone' : 'serial', $isPhone ? $phone : RepairOperations::serial($q))];
            }
        }
        // Persist only keyed search digests, never plaintext identifiers or references.
        $request->session()->put('repair_search', $criteria);

        return redirect()->route('admin.repair-cases.index');
    }

    public function resetSearch(Request $request): RedirectResponse
    {
        $request->session()->forget('repair_search');

        return redirect()->route('admin.repair-cases.index');
    }

    public function create(Request $request): View
    {
        foreach (['repairs.intake', 'repairs.customer_data'] as $permission) {
            Gate::authorize($permission);
        }
        $source = null;
        if ($request->filled('request')) {
            $request->validate(['request' => ['integer', 'min:1']]);
            $source = RepairRequest::query()->findOrFail($request->query('request'));
            Gate::authorize('viewCustomerData', $source);
            abort_if($source->physicalCase()->exists(), 409, 'پذیرش این درخواست قبلاً ثبت شده است.');
            AdminHistory::record(AdminAction::RepairCustomerViewed, 'repair_request', $source->id);
        }
        $token = bin2hex(random_bytes(32));
        $request->session()->put('repair_intake_token', $token);

        return view('admin.repair-cases.intake', ['source' => $source, 'token' => $token, 'brands' => RepairBrand::cases()]);
    }

    public function store(RepairOperationRequest $request, RepairOperations $operations): RedirectResponse
    {
        $issued = $request->session()->get('repair_intake_token');
        abort_unless(is_string($issued) && hash_equals($issued, $request->validated('submission_token')), 409, 'فرم پذیرش منقضی یا قبلاً ثبت شده است؛ فرم تازه باز کنید.');
        $case = $operations->intake($request->user(), $request->validated());
        $request->session()->forget('repair_intake_token');

        return redirect()->route('admin.repair-cases.show', $case)->with('success', 'پذیرش فیزیکی ثبت شد؛ در صورت داشتن مجوز، رسید را از اسناد پرونده چاپ کنید.');
    }

    public function show(RepairCase $repairCase): View
    {
        Gate::authorize('view', $repairCase);
        $customer = Gate::allows('viewCustomerData', $repairCase);
        if ($customer) {
            AdminHistory::record(AdminAction::RepairCustomerViewed, 'repair_case', $repairCase->id);
        }
        $repairCase->load(['receiver:id,name', 'technician:id,name']);

        return view('admin.repair-cases.show', ['case' => $repairCase, 'customer' => $customer, 'technicians' => Gate::allows('operate', [$repairCase, 'assign']) ? $this->technicians() : collect(), 'events' => $repairCase->events()->with('actor:id,name')->latest('id')->paginate(20), 'inspections' => $customer ? $repairCase->inspections()->with('actor:id,name')->latest('id')->get() : collect(), 'estimates' => $customer ? $repairCase->estimates()->with(['decision', 'actor:id,name'])->latest('revision')->get() : collect(), 'work' => $customer ? $repairCase->workEntries()->with('actor:id,name')->latest('id')->get() : collect(), 'delivery' => $customer ? $repairCase->delivery : null]);
    }

    public function update(RepairOperationRequest $request, RepairCase $repairCase, RepairOperations $operations): RedirectResponse
    {
        $operations->update($request->user(), $repairCase, $request->operation(), $request->validated());

        return redirect()->route('admin.repair-cases.show', $repairCase)->with('success', 'عملیات ثبت شد؛ نسخهٔ پرونده و تاریخچه به‌روز است.');
    }

    public function document(RepairCase $repairCase, string $document): View
    {
        Gate::authorize('operate', [$repairCase, 'print']);
        abort_unless(in_array($document, ['intake', 'summary', 'delivery'], true), 404);
        abort_if($document === 'delivery' && ! $repairCase->delivery()->exists(), 404);
        AdminHistory::record(AdminAction::RepairDocument, 'repair_case', $repairCase->id);
        $repairCase->load(['receiver:id,name', 'technician:id,name', 'currentEstimate.decision', 'workEntries', 'delivery.actor:id,name']);

        return view('admin.repair-cases.document', ['case' => $repairCase, 'document' => $document]);
    }
}
