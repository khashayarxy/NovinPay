<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\RepairRequest;
use App\Support\AdminHistory;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RepairOverviewController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'pending', 'accepted'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'all';
        $term = trim((string) ($filters['q'] ?? ''));
        $canReadCustomer = Gate::allows('repairs.customer_data');

        $counts = [
            'all' => RepairRequest::query()->count(),
            'pending' => RepairRequest::query()->whereDoesntHave('physicalCase')->count(),
            'accepted' => RepairRequest::query()->whereHas('physicalCase')->count(),
        ];

        $columns = ['id', 'tracking_reference', 'device_brand', 'status', 'created_at'];
        if ($canReadCustomer) {
            $columns = array_merge($columns, ['customer_name', 'device_model', 'issue']);
        }

        $query = RepairRequest::query()->select($columns)->withExists('physicalCase');
        if ($status === 'pending') {
            $query->whereDoesntHave('physicalCase');
        } elseif ($status === 'accepted') {
            $query->whereHas('physicalCase');
        }

        if ($term !== '') {
            $normalized = FaNumber::normalizeDigits($term);
            $needle = mb_strtolower($normalized);
            $phoneNeedle = RepairIdentity::phone($normalized);
            $scanColumns = $canReadCustomer
                ? ['id', 'tracking_reference', 'customer_name', 'contact_phone', 'device_model']
                : ['id', 'tracking_reference'];

            $matchedIds = [];
            foreach ((clone $query)->select($scanColumns)->cursor() as $candidate) {
                $matchesRef = str_contains(mb_strtolower((string) $candidate->tracking_reference), $needle)
                    || (ctype_digit(ltrim($normalized, '#')) && (string) $candidate->id === ltrim($normalized, '#'));
                $matchesCustomer = $canReadCustomer && (
                    str_contains(mb_strtolower((string) $candidate->customer_name.' '.(string) $candidate->device_model), $needle)
                    || ($phoneNeedle !== '' && str_contains(RepairIdentity::phone((string) $candidate->contact_phone), $phoneNeedle))
                );
                if ($matchesRef || $matchesCustomer) {
                    $matchedIds[] = $candidate->id;
                }
            }
            $query->whereIn('id', $matchedIds ?: [0]);
        }

        return view('admin.repairs.index', [
            'repairs' => $query->latest('id')->paginate(20)->appends(array_filter(['status' => $status !== 'all' ? $status : null, 'q' => $term !== '' ? $term : null])),
            'canReadCustomer' => $canReadCustomer,
            'status' => $status,
            'searchQuery' => $term,
            'counts' => $counts,
        ]);
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
