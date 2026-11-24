<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\RepairRequest;
use App\Support\AdminHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RepairOverviewController extends Controller
{
    public function index(): View
    {
        return view('admin.repairs.index', ['repairs' => RepairRequest::query()
            ->select(['id', 'tracking_reference', 'device_brand', 'status', 'created_at'])->withExists('physicalCase')->latest('id')->paginate(20)]);
    }

    public function photo(Request $request, RepairRequest $repairRequest)
    {
        Gate::authorize('viewCustomerData', $repairRequest);
        $disk = Storage::disk('local');
        $side = $request->query('side');
        if (is_string($side) && array_key_exists($side, RepairRequest::PHOTO_SIDES)) {
            $sidePhotos = $repairRequest->existingSidePhotos();
            abort_unless(isset($sidePhotos[$side]), 404);
            $path = $sidePhotos[$side];
            $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'webp';
            AdminHistory::record(AdminAction::RepairCustomerViewed, 'repair_request', $repairRequest->id);

            return $disk->download($path, "repair-photo-{$side}.{$ext}", ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        $path = $repairRequest->photoPath();
        abort_unless($disk->exists($path), 404);
        AdminHistory::record(AdminAction::RepairCustomerViewed, 'repair_request', $repairRequest->id);

        return $disk->download($path, 'repair-photo', ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function show(RepairRequest $repairRequest): View
    {
        Gate::authorize('view', $repairRequest);
        $showCustomerData = Gate::allows('viewCustomerData', $repairRequest);
        if ($showCustomerData) {
            AdminHistory::record(AdminAction::RepairCustomerViewed, 'repair_request', $repairRequest->id);
        }

        return view('admin.repairs.show', [
            'repair' => $repairRequest,
            'showCustomerData' => $showCustomerData,
            'hasPhoto' => $showCustomerData && Storage::disk('local')->exists($repairRequest->photoPath()),
            'sidePhotos' => $showCustomerData ? $repairRequest->existingSidePhotos() : [],
            'physicalCase' => $repairRequest->physicalCase()->select('id', 'public_id')->first(),
        ]);
    }
}
