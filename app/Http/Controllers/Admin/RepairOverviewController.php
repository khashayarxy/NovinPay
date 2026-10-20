<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\RepairRequest;
use App\Support\AdminHistory;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RepairOverviewController extends Controller
{
    public function index(): View
    {
        return view('admin.repairs.index', ['repairs' => RepairRequest::query()
            ->select(['id', 'tracking_reference', 'device_brand', 'status', 'created_at'])->withExists('physicalCase')->latest('id')->paginate(20)]);
    }

    public function show(RepairRequest $repairRequest): View
    {
        Gate::authorize('view', $repairRequest);
        $showCustomerData = Gate::allows('viewCustomerData', $repairRequest);
        if ($showCustomerData) {
            AdminHistory::record(AdminAction::RepairCustomerViewed, 'repair_request', $repairRequest->id);
        }

        return view('admin.repairs.show', ['repair' => $repairRequest, 'showCustomerData' => $showCustomerData, 'physicalCase' => $repairRequest->physicalCase()->select('id', 'public_id')->first()]);
    }
}
