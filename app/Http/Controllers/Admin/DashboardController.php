<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\AdminActivity;
use App\Models\InventoryItem;
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
            'repairCount' => Gate::allows('repairs.view') ? RepairRequest::query()->where('status', 'online_submitted')->whereDoesntHave('physicalCase')->count() : null,
            'openRepairCount' => Gate::allows('repairs.view') ? RepairCase::query()->whereNotIn('status', ['delivered', 'returned'])->count() : null,
            'recentRepairs' => Gate::allows('repairs.view') ? RepairRequest::query()->select(['id', 'tracking_reference', 'device_brand', 'status', 'created_at'])->withExists('physicalCase')->latest('id')->limit(5)->get() : collect(),
            'recentActivities' => Gate::allows('activity.view')
                ? AdminActivity::query()->whereNotIn('action', [AdminAction::Login->value, AdminAction::Logout->value])->latest('id')->limit(4)->get()
                : collect(),
        ]);
    }
}
