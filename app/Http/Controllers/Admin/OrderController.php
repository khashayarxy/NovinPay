<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommerceRequest;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\SalesOrder;
use App\Services\CommerceManagement;
use App\Support\AdminHistory;
use App\Support\CommerceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(array_keys(SalesOrder::STATUSES))], 'reference' => ['nullable', 'uuid']]);
        $query = SalesOrder::query()->select(['id', 'public_id', 'status', 'currency', 'total', 'created_at']);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['reference'])) {
            $query->where('public_id', $filters['reference']);
        }

        return view('admin.orders.index', ['orders' => $query->latest('id')->paginate(20)->appends($filters), 'filters' => $filters]);
    }

    public function create(Request $request)
    {
        CommerceAccess::authorize(auth()->user(), 'order.save');
        $criteria = $request->validate(['customer' => ['nullable', 'uuid']]);

        return view('admin.orders.form', ['order' => null, 'lines' => collect(), 'customerReference' => $criteria['customer'] ?? '', 'suggestedItems' => $this->suggestedItems()]);
    }

    public function edit(SalesOrder $order)
    {
        CommerceAccess::authorize(auth()->user(), 'order.save');
        abort_unless($order->status === 'draft', 409, 'فقط پیش‌نویس قابل ویرایش است.');

        return view('admin.orders.form', ['order' => $order->load('customer:id,public_id'), 'suggestedItems' => $this->suggestedItems(), 'lines' => $order->lines()->join('inventory_items', 'inventory_items.id', '=', 'sales_order_lines.inventory_item_id')->get(['sales_order_lines.*', 'inventory_items.public_id as item_reference'])]);
    }

    private function suggestedItems()
    {
        return InventoryItem::query()->orderBy('id')->limit(100)->get(['public_id', 'sku', 'name', 'condition', 'on_hand', 'reserved']);
    }

    public function store(CommerceRequest $request, CommerceManagement $management)
    {
        $id = $management->execute($request->user(), 'order.save', $request->validated());

        return redirect()->route('admin.orders.show', SalesOrder::findOrFail($id))->with('success', 'پیش‌نویس ثبت شد؛ هنوز موجودی رزرو یا تحویل نشده است.');
    }

    public function update(CommerceRequest $request, SalesOrder $order, CommerceManagement $management)
    {
        $management->execute($request->user(), $request->operation(), $request->validated(), $order);

        return redirect()->route('admin.orders.show', $order)->with('success', 'عملیات سفارش ثبت شد؛ این ثبت، تأیید پرداخت نیست.');
    }

    public function show(SalesOrder $order)
    {
        $contact = CommerceAccess::contact();
        $customer = Customer::query()->select($contact ? ['id', 'public_id', 'name', 'contact_phone', 'is_active', 'merged_into_id'] : ['id', 'public_id'])->findOrFail($order->customer_id);
        if ($contact) {
            AdminHistory::record(AdminAction::OrderCustomerViewed, 'sales_order', $order->id);
        }
        $lines = $order->lines()->orderBy('id')->get();
        $reservations = DB::table('stock_reservations')->leftJoin('inventory_units', 'inventory_units.id', '=', 'stock_reservations.inventory_unit_id')->whereIn('sales_order_line_id', $lines->modelKeys())->whereIn('status', ['active', 'fulfilled'])->orderBy('stock_reservations.id')->paginate(30, ['stock_reservations.status', 'stock_reservations.quantity', 'inventory_units.serial']);

        return view('admin.orders.show', ['order' => $order, 'customer' => $customer, 'contact' => $contact, 'lines' => $lines, 'reservations' => $reservations, 'events' => $order->events()->latest('id')->paginate(15, ['*'], 'history_page')]);
    }
}
