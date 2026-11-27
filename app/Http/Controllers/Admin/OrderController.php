<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommerceRequest;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\SalesOrder;
use App\Services\ActivationDocumentsService;
use App\Services\CommerceManagement;
use App\Support\AdminHistory;
use App\Support\CommerceAccess;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(SalesOrder::STATUSES))],
            'q' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'uuid'],
        ]);
        $contact = CommerceAccess::contact();
        $term = trim((string) ($filters['q'] ?? $filters['reference'] ?? ''));

        $relations = ['lines:id,sales_order_id,description,quantity'];
        if ($contact) {
            $relations[] = 'customer:id,public_id,name,contact_phone';
        }

        $query = SalesOrder::query()
            ->select(['id', 'public_id', 'customer_id', 'status', 'currency', 'total', 'created_at'])
            ->with($relations);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if ($term !== '') {
            if (Str::isUuid($term)) {
                $query->where('public_id', $term);
            } else {
                $normalized = FaNumber::normalizeDigits($term);
                $needle = mb_strtolower($normalized);
                $numericId = ctype_digit(ltrim($normalized, '#')) ? (int) ltrim($normalized, '#') : null;
                $phoneNeedle = RepairIdentity::phone($normalized);

                $customerIds = [];
                if ($contact) {
                    foreach (Customer::query()->select(['id', 'name', 'contact_phone'])->cursor() as $candidate) {
                        $matchesName = str_contains(mb_strtolower((string) $candidate->name), $needle);
                        $matchesPhone = $phoneNeedle !== '' && str_contains(RepairIdentity::phone((string) $candidate->contact_phone), $phoneNeedle);
                        if ($matchesName || $matchesPhone) {
                            $customerIds[] = $candidate->id;
                        }
                    }
                }

                $escapedPrefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], strtolower($normalized)).'%';
                $query->where(function ($sub) use ($numericId, $escapedPrefix, $customerIds): void {
                    $sub->whereRaw("LOWER(public_id) LIKE ? ESCAPE '!'", [$escapedPrefix]);
                    if ($numericId !== null) {
                        $sub->orWhere('id', $numericId);
                    }
                    if ($customerIds !== []) {
                        $sub->orWhereIn('customer_id', $customerIds);
                    }
                });
            }
        }

        return view('admin.orders.index', [
            'orders' => $query->latest('id')->paginate(20)->appends($filters),
            'filters' => $filters + ['q' => $term],
            'contact' => $contact,
        ]);
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

    public function approveDocuments(Request $request, SalesOrder $order, ActivationDocumentsService $service)
    {
        abort_unless($request->user()->can('orders.confirm') && CommerceAccess::contact(), 403);
        $service->approve($order, $request->user());

        return redirect()->route('admin.orders.show', $order)->with('success', 'وضعیت مدارک فعال‌سازی به «تأیید شده» تغییر یافت.');
    }

    public function revisionDocuments(Request $request, SalesOrder $order, ActivationDocumentsService $service)
    {
        abort_unless($request->user()->can('orders.confirm') && CommerceAccess::contact(), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ], [
            'reason.required' => 'لطفاً علت نیاز به اصلاح مدارک را به فارسی بنویسید.',
        ]);

        $service->requestRevision($order, $request->user(), $data['reason']);

        return redirect()->route('admin.orders.show', $order)->with('success', 'وضعیت مدارک به «نیاز به اصلاح» تغییر یافت و به مشتری اطلاع‌رسانی شد.');
    }

    public function purgeDocuments(Request $request, SalesOrder $order, ActivationDocumentsService $service)
    {
        abort_unless($request->user()->can('orders.confirm') && CommerceAccess::contact(), 403);
        $service->purgeOrderDocuments($order, $request->user());

        return redirect()->route('admin.orders.show', $order)->with('success', 'فایل مدارک از تلگرام و حافظه موقت پاکسازی شد.');
    }

    public function retryDocumentsTelegram(Request $request, SalesOrder $order, ActivationDocumentsService $service)
    {
        abort_unless($request->user()->can('orders.confirm') && CommerceAccess::contact(), 403);
        if ($service->retryRetainedTelegramDelivery($order)) {
            return redirect()->route('admin.orders.show', $order)->with('success', 'فایل رمزنگاری‌شده به تلگرام ارسال و از سرور حذف شد.');
        }

        return redirect()->route('admin.orders.show', $order)->withErrors(['documents' => 'ارسال مجدد به تلگرام ناموفق بود یا فایلی در سرور وجود ندارد.']);
    }
}
