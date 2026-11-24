<?php

namespace App\Http\Controllers;

use App\Models\CustomerAddress;
use App\Models\DeliveryPlan;
use App\Models\FinancialDocument;
use App\Models\RepairCase;
use App\Models\SalesOrder;
use App\Services\DeliveryManagement;
use App\Services\ZibalPayments;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        $order = FinancialDocument::query()->select('sales_order_id')->whereColumn('financial_documents.id', 'delivery_plans.financial_document_id');

        $plans = DeliveryPlan::query()->addSelect(['sales_order_id' => $order])
            ->whereIn('financial_document_id', $documents)->where('state', 'planned')->latest('id')->paginate(20);

        $planItems = collect($plans->items());
        $docIds = $planItems->pluck('financial_document_id')->filter()->unique()->values()->all();
        $planIds = $planItems->pluck('id')->filter()->unique()->values()->all();

        $docs = $docIds !== []
            ? FinancialDocument::query()->whereIn('id', $docIds)->get(['id', 'public_id', 'sales_order_id', 'repair_case_id', 'charged_minor', 'paid_minor'])->keyBy('id')
            : collect();

        $orderIds = $docs->pluck('sales_order_id')->filter()->unique()->values()->all();
        $repairIds = $docs->pluck('repair_case_id')->filter()->unique()->values()->all();

        $orders = $orderIds !== []
            ? SalesOrder::query()->with(['customer:id,public_id,name,contact_phone', 'lines:id,sales_order_id,description,quantity'])->whereIn('id', $orderIds)->get(['id', 'public_id', 'customer_id', 'status', 'total', 'created_at'])->keyBy('id')
            : collect();
        $repairs = $repairIds !== []
            ? RepairCase::query()->whereIn('id', $repairIds)->get(['id', 'public_id', 'tracking_reference', 'customer_name', 'contact_phone', 'device_brand', 'device_model', 'created_at'])->keyBy('id')
            : collect();
        $eventsByPlan = $planIds !== []
            ? DB::table('delivery_events')->whereIn('delivery_plan_id', $planIds)->get(['delivery_plan_id', 'action'])->groupBy('delivery_plan_id')
            : collect();

        $planContext = [];
        foreach ($planItems as $plan) {
            $doc = $docs->get($plan->financial_document_id);
            $salesOrder = $doc?->sales_order_id ? $orders->get($doc->sales_order_id) : null;
            $repairCase = $doc?->repair_case_id ? $repairs->get($doc->repair_case_id) : null;
            $actions = $eventsByPlan->get($plan->id, collect())->pluck('action')->all();

            if ($salesOrder) {
                $firstLine = $salesOrder->lines->first();
                $extraLines = max(0, $salesOrder->lines->count() - 1);
                $itemsSummary = $firstLine
                    ? $firstLine->description.' × '.FaNumber::digits($firstLine->quantity).($extraLines > 0 ? ' و '.FaNumber::digits($extraLines).' قلم دیگر' : '')
                    : 'سفارش فروش';
                $code = 'سفارش #'.FaNumber::digits($salesOrder->id);
                $customerName = $salesOrder->customer?->name;
                $customerPhone = $salesOrder->customer?->contact_phone;
                $date = $salesOrder->created_at;
                $sourceUrl = route('admin.orders.show', $salesOrder);
            } elseif ($repairCase) {
                $ref = (string) $repairCase->tracking_reference;
                $code = 'تعمیر '.(preg_match('/\A[0-9]+\z/', $ref) ? FaNumber::digits($ref) : substr($ref, 0, 10));
                $itemsSummary = RepairIdentity::deviceLabel($repairCase->device_brand, $repairCase->device_model);
                $customerName = $repairCase->customer_name;
                $customerPhone = $repairCase->contact_phone;
                $date = $repairCase->created_at;
                $sourceUrl = route('admin.repair-cases.show', $repairCase);
            } else {
                $code = 'تحویل #'.FaNumber::digits($plan->id);
                $itemsSummary = '';
                $customerName = null;
                $customerPhone = null;
                $date = $plan->created_at;
                $sourceUrl = null;
            }

            $planContext[$plan->id] = [
                'code' => $code,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'items_summary' => $itemsSummary,
                'date' => $date,
                'source_url' => $sourceUrl,
                'document_url' => $doc ? route('admin.finance.documents.show', $doc->public_id) : null,
                'is_settled' => $doc ? ($doc->charged_minor === $doc->paid_minor) : true,
                'outstanding_minor' => $doc ? max(0, $doc->charged_minor - $doc->paid_minor) : 0,
                'preparing_done' => in_array('order_preparing', $actions, true),
                'pickup_ready_done' => in_array('order_pickup_ready', $actions, true),
            ];
        }

        return view('admin.deliveries', [
            'plans' => $plans,
            'planContext' => $planContext,
        ]);
    }

    public function confirm(Request $request, string $plan, DeliveryManagement $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'operation_key' => ['required', 'uuid'], 'password' => ['nullable', 'string', 'max:255']]);
        $service->confirm($request->user(), $data['password'] ?? null, $plan, (int) $data['version'], $data['operation_key']);

        return back()->with('success', 'روش تحویل و هزینهٔ آن تأیید شد.');
    }

    public function dispatch(Request $request, string $plan, DeliveryManagement $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'password' => ['nullable', 'string', 'max:255'], 'tracking' => ['required', 'string', 'min:3', 'max:200'], 'recipient' => ['required', 'string', 'min:2', 'max:100'], 'handover_confirmed' => ['accepted']]);
        $service->dispatch($request->user(), $data['password'] ?? null, $plan, (int) $data['version'], $data['tracking'], $data['recipient']);

        return back()->with('success', 'تحویل واقعی ثبت شد؛ روش تحویل دیگر قابل تغییر نیست.');
    }

    public function announceOrderProgress(Request $request, string $plan, DeliveryManagement $service)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'password' => ['nullable', 'string', 'max:255'], 'progress' => ['required', 'in:preparing,pickup_ready']]);
        $service->announceOrderProgress($request->user(), $data['password'] ?? null, $plan, (int) $data['version'], $data['progress']);

        return back()->with('success', 'وضعیت آماده‌سازی سفارش ثبت شد.');
    }
}
