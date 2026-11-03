<?php

namespace App\Http\Controllers;

use App\Models\CustomerAddress;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Services\DeliveryManagement;
use App\Services\ZibalPayments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeliveryController extends Controller
{
    public function show(string $document, ZibalPayments $payments)
    {
        $account = Auth::guard('customer')->user();
        $document = $payments->ownedDocument($account, $document);

        return view('account.delivery', ['document' => $document, 'plan' => DeliveryPlan::query()->where('financial_document_id', $document->id)->first(), 'addresses' => CustomerAddress::query()->where('customer_account_id', $account->id)->get()]);
    }

    public function request(Request $request, string $document, DeliveryManagement $service)
    {
        $data = $request->validate(['choice' => ['required', 'in:shipping,pickup'], 'carrier' => ['required_if:choice,shipping', 'nullable', 'in:post,tipax,courier'], 'address' => ['required_if:choice,shipping', 'nullable', 'uuid'], 'version' => ['required', 'integer', 'min:0']]);
        $service->request(Auth::guard('customer')->user(), $document, $data['choice'] === 'pickup' ? 'pickup' : $data['carrier'], $data['address'] ?? null, (int) $data['version']);

        return back()->with('success', 'درخواست تغییر روش تحویل ثبت شد؛ همکاران باید آن را تأیید کنند.');
    }

    public function admin(Request $request)
    {
        $documents = FinancialDocument::query()->where(function ($q) use ($request): void {
            $q->whereRaw('1=0');
            if ($request->user()->can('orders.fulfill') && $request->user()->can('orders.customer_data')) {
                $q->orWhereNotNull('sales_order_id');
            }
            if ($request->user()->can('repairs.deliver') && $request->user()->can('repairs.customer_data')) {
                $q->orWhereNotNull('repair_case_id');
            }
        })->select('id');

        return view('admin.deliveries', ['plans' => DeliveryPlan::query()->whereIn('financial_document_id', $documents)->where('state', 'planned')->latest('id')->paginate(20)]);
    }

    public function confirm(Request $request, string $plan, DeliveryManagement $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'operation_key' => ['required', 'uuid'], 'password' => ['required', 'string', 'max:255']]);
        $service->confirm($request->user(), $data['password'], $plan, (int) $data['version'], $data['operation_key']);

        return back()->with('success', 'روش تحویل و هزینهٔ آن تأیید شد.');
    }

    public function dispatch(Request $request, string $plan, DeliveryManagement $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'password' => ['required', 'string', 'max:255'], 'tracking' => ['required', 'string', 'min:3', 'max:200'], 'recipient' => ['required', 'string', 'min:2', 'max:100'], 'handover_confirmed' => ['accepted']]);
        $service->dispatch($request->user(), $data['password'], $plan, (int) $data['version'], $data['tracking'], $data['recipient']);

        return back()->with('success', 'تحویل واقعی ثبت شد؛ روش تحویل دیگر قابل تغییر نیست.');
    }
}
