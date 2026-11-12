<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RepairOperationRequest;
use App\Http\Requests\SimpleRepairRequest;
use App\Models\FinancialDocument;
use App\Models\RepairCase;
use App\Models\RepairRecordRevision;
use App\Models\RepairRequest;
use App\Services\RepairOperations;
use App\Services\SimpleRepairRecords;
use App\Support\AdminHistory;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RepairCaseController extends Controller
{
    public function index(Request $request): View
    {
        $criteria = $request->session()->get('repair_search', []);
        $query = RepairCase::query();
        if (! empty($criteria['status'])) {
            $states = match ($criteria['status']) {
                'received' => ['received', 'waiting_inspection', 'inspecting', 'inspected', 'waiting_approval', 'approved'],
                'ready' => ['ready', 'repaired'], 'return_pending' => ['return_pending', 'returned'], default => [$criteria['status']],
            };
            $query->whereIn('status', $states);
        }
        if (! empty($criteria['search_column']) && in_array($criteria['search_column'], ['tracking_digest', 'phone_digest', 'public_id', 'serial_digest'], true)) {
            if (in_array($criteria['search_column'], ['phone_digest', 'serial_digest'], true)) {
                Gate::authorize('repairs.customer_data');
            }
            $query->where($criteria['search_column'], $criteria['search_value']);
        }
        if (! empty($criteria['query_cipher'])) {
            Gate::authorize('repairs.customer_data');
            $needle = mb_strtolower(Crypt::decryptString($criteria['query_cipher']));
            $ids = [];
            // Names/models remain encrypted at rest. Only authorized matching hydrates them;
            // the retained search state contains ciphertext, not customer input or SQL fragments.
            foreach ((clone $query)->select(['id', 'customer_name', 'device_model'])->cursor() as $candidate) {
                if (str_contains(mb_strtolower($candidate->customer_name.' '.$candidate->device_model), $needle)) {
                    $ids[] = $candidate->id;
                }
            }
            $query->whereIn('id', $ids);
        }

        return view('admin.repair-cases.index', ['cases' => $query->latest('id')->paginate(20), 'criteria' => $criteria, 'canReadCustomer' => Gate::allows('repairs.customer_data')]);
    }

    public function search(RepairOperationRequest $request): RedirectResponse
    {
        $criteria = array_filter(['status' => $request->validated('status')]);
        $q = FaNumber::normalizeDigits(trim($request->validated('q') ?? ''));
        if ($q !== '') {
            if (preg_match('/\A(?:[0-9]{1,8}|NP-[A-F0-9]{32})\z/i', $q)) {
                $criteria += ['search_column' => 'tracking_digest', 'search_value' => RepairIdentity::digest('tracking', strtoupper($q))];
            } elseif (preg_match('/\A09[0-9]{9}\z/', RepairIdentity::phone($q))) {
                Gate::authorize('repairs.customer_data');
                $criteria += ['search_column' => 'phone_digest', 'search_value' => RepairIdentity::digest('phone', RepairIdentity::phone($q))];
            } else {
                Gate::authorize('repairs.customer_data');
                $criteria['query_cipher'] = Crypt::encryptString($q);
            }
        }
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

        return view('admin.repair-cases.intake', ['source' => $source, 'token' => $token]);
    }

    public function store(RepairOperationRequest $request, RepairOperations $operations): RedirectResponse
    {
        $issued = $request->session()->get('repair_intake_token');
        abort_unless(is_string($issued) && hash_equals($issued, $request->validated('submission_token')), 409, 'فرم پذیرش منقضی یا قبلاً ثبت شده است؛ فرم تازه باز کنید.');
        $case = $operations->intake($request->user(), $request->validated());
        $request->session()->forget('repair_intake_token');

        return redirect()->route('admin.repair-cases.show', $case)->with('success', 'پذیرش ثبت شد.');
    }

    public function show(RepairCase $repairCase): View
    {
        Gate::authorize('view', $repairCase);
        $customer = Gate::allows('viewCustomerData', $repairCase);
        if ($customer) {
            AdminHistory::record(AdminAction::RepairCustomerViewed, 'repair_case', $repairCase->id);
        }
        $repairCase->load(['currentEstimate', 'onlineRequest:id,tracking_reference', 'delivery']);
        $document = $customer && Gate::allows('finance.view') ? FinancialDocument::query()->where('repair_case_id', $repairCase->id)->first() : null;
        $hasDocument = $document !== null || ($customer && Gate::allows('repairs.estimate') && ! Gate::allows('finance.view')
            && FinancialDocument::query()->where('repair_case_id', $repairCase->id)->exists());
        $financial = ['repairDocument' => $document, 'hasRepairDocument' => $hasDocument];

        $events = $repairCase->events()->with('actor:id,name')->latest('id')->paginate(20);
        $revisions = $customer ? RepairRecordRevision::query()->where('repair_case_id', $repairCase->id)
            ->whereIn('id', $events->getCollection()->where('subject_type', 'repair_revision')->pluck('subject_id'))->get()->keyBy('id') : collect();

        return view('admin.repair-cases.simple-record', $financial + ['case' => $repairCase, 'customer' => $customer, 'events' => $events, 'revisions' => $revisions]);
    }

    public function saveSimple(SimpleRepairRequest $request, RepairCase $repairCase, SimpleRepairRecords $records): RedirectResponse
    {
        $records->save($request->user(), $repairCase, $request->validated());

        return redirect()->route('admin.repair-cases.show', $repairCase)->with('success', match ($request->validated('intent')) {
            'settlement' => 'روش پرداخت ثبت شد.',
            'handover' => 'تحویل دستگاه ثبت شد.',
            default => 'تغییرات پرونده ذخیره شد.',
        });
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

        if ($repairCase->simple_workflow) {
            $invoice = Gate::allows('finance.view') ? FinancialDocument::query()->where('repair_case_id', $repairCase->id)->first() : null;

            return view('admin.repair-cases.simple-document', ['case' => $repairCase, 'document' => $document, 'invoice' => $invoice]);
        }

        return view('admin.repair-cases.document', ['case' => $repairCase, 'document' => $document]);
    }
}
