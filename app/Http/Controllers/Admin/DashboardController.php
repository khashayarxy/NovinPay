<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccount;
use App\Models\AdminActivity;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'pendingOrderCount' => Gate::allows('orders.view') ? SalesOrder::query()->whereIn('status', ['draft', 'reserved', 'confirmed'])->count() : null,
            'lowStockCount' => Gate::allows('inventory.view') ? InventoryItem::query()->where('is_low_stock', true)->count() : null,
            'productCount' => Gate::allows('products.view') ? Product::query()->count() : null,
            'publishedCount' => Gate::allows('products.view') ? Product::query()->published()->count() : null,
            'repairCount' => Gate::allows('repairs.view') ? RepairRequest::query()->where('status', 'online_submitted')->whereDoesntHave('physicalCase')->count() : null,
            'staffCount' => Gate::allows('staff.manage') ? AdminAccount::query()->where('role', 'staff')->where('is_active', true)->count() : null,
            'openRepairCount' => Gate::allows('repairs.view') ? RepairCase::query()->whereNotIn('status', ['delivered', 'returned'])->count() : null,
            'readyRepairCount' => Gate::allows('repairs.view') ? RepairCase::query()->where('status', 'ready')->count() : null,
            'recentRepairs' => Gate::allows('repairs.view') ? RepairRequest::query()->select(['id', 'tracking_reference', 'device_brand', 'status', 'created_at'])->withExists('physicalCase')->latest('id')->limit(5)->get() : collect(),
            'recentActivities' => Gate::allows('activity.view') ? AdminActivity::query()->latest('id')->limit(6)->get() : collect(),
        ]);
    }
}
