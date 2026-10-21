<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CrmOperationRequest;
use App\Http\Requests\CrmSearchRequest;
use App\Models\Customer;
use App\Services\CustomerManagement;
use App\Support\AdminHistory;
use App\Support\CrmAccess;
use App\Support\RepairIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $criteria = $request->session()->get('customer_search', []);
        $contact = Gate::allows('customers.contact');
        $query = Customer::query()->select($contact ? ['id', 'public_id', 'name', 'contact_phone', 'is_active', 'merged_into_id', 'created_at'] : ['id', 'public_id', 'is_active', 'merged_into_id', 'created_at']);
        if (isset($criteria['active']) && $criteria['active'] !== '') {
            $query->where('is_active', (bool) $criteria['active']);
        }
        if ($contact && isset($criteria['digest'])) {
            $query->where($criteria['column'], $criteria['digest']);
        }

        return view('admin.customers.index', ['customers' => $query->latest('id')->paginate(20), 'contact' => $contact, 'criteria' => $criteria, 'total' => Customer::query()->count(), 'active' => Customer::query()->where('is_active', true)->count()]);
    }

    public function search(CrmSearchRequest $request)
    {
        $data = $request->validated();
        $criteria = ['active' => $data['active'] ?? ''];
        if (! empty($data['q'])) {
            Gate::authorize('customers.contact');
            $phone = RepairIdentity::phone($data['q']);
            $isPhone = preg_match('/\A09[0-9]{9}\z/', $phone);
            $criteria += ['column' => $isPhone ? 'phone_digest' : 'name_digest', 'digest' => RepairIdentity::digest($isPhone ? 'customer-phone' : 'customer-name', $isPhone ? $phone : CrmAccess::name($data['q']))];
        }
        $request->session()->put('customer_search', $criteria);

        return redirect()->route('admin.customers.index');
    }

    public function create()
    {
        CrmAccess::require(auth()->user(), CrmAccess::permissions('customer.save'));

        return view('admin.customers.form', ['customer' => null]);
    }

    public function edit(Customer $customer)
    {
        CrmAccess::require(auth()->user(), CrmAccess::permissions('customer.save'));
        abort_if($customer->merged_into_id, 409, 'پروفایل تطبیق‌یافته فقط خواندنی است.');

        return view('admin.customers.form', compact('customer'));
    }

    public function store(CrmOperationRequest $request, CustomerManagement $management)
    {
        $customer = $management->save($request->user(), $request->validated());

        return redirect()->route('admin.customers.show', $customer)->with('success', 'پروفایل پس از بررسی هویت ثبت شد.');
    }

    public function update(CrmOperationRequest $request, Customer $customer, CustomerManagement $management)
    {
        $management->save($request->user(), $request->validated(), $customer);

        return redirect()->route('admin.customers.show', $customer)->with('success', 'پروفایل ویرایش شد؛ اطلاعات تاریخی تعمیرات تغییر نکرد.');
    }

    public function show(Customer $customer)
    {
        $contact = Gate::allows('customers.contact');
        $notes = $contact && Gate::allows('customers.notes');
        if ($contact) {
            AdminHistory::record(AdminAction::CustomerViewed, 'customer', $customer->id);
        }
        $repairAccess = Gate::allows('repairs.view');
        $consultationAccess = Gate::allows('consultations.view');

        return view('admin.customers.show', [
            'customer' => $customer, 'contact' => $contact,
            'notes' => $notes ? $customer->notes()->with('actor:id,name')->latest('id')->paginate(15, ['*'], 'notes_page') : null,
            'duplicates' => $contact ? Customer::query()->where('phone_digest', $customer->phone_digest)->whereKeyNot($customer->id)->limit(20)->get() : collect(),
            'predecessors' => Customer::query()->where('merged_into_id', $customer->id)->select('public_id')->limit(20)->get(),
            'requests' => $repairAccess ? $customer->repairRequests()->select('id', 'device_brand', 'status', 'created_at')->latest('id')->paginate(10, ['*'], 'requests_page') : null,
            'cases' => $repairAccess ? $customer->repairCases()->select('public_id', 'device_brand', 'status', 'received_at')->latest('id')->paginate(10, ['*'], 'cases_page') : null,
            'consultations' => $consultationAccess ? $customer->consultations()->select('public_id', 'topic', 'status', 'created_at')->latest('id')->paginate(10, ['*'], 'consultations_page') : null,
        ]);
    }

    public function operation(CrmOperationRequest $request, Customer $customer, CustomerManagement $management)
    {
        $data = $request->validated();
        match ($request->operation()) {
            'customer.link' => $management->link($request->user(), $customer, $data),
            'customer.note' => $management->addNote($request->user(), $customer, $data),
            'customer.reconcile' => $management->reconcile($request->user(), $customer, $data),
            default => abort(404),
        };

        return redirect()->route('admin.customers.show', $customer)->with('success', 'عملیات مشتری ثبت شد؛ اطلاعات تاریخی محفوظ است.');
    }
}
