<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\CrmOperationRequest;
use App\Http\Requests\CrmSearchRequest;
use App\Models\AdminAccount;
use App\Models\Consultation;
use App\Models\User;
use App\Services\ConsultationManagement;
use App\Support\AdminHistory;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

class ConsultationController extends Controller
{
    public function index(Request $request)
    {
        $criteria = $request->session()->get('consultation_search', []);
        $contact = Gate::allows('consultations.contact');
        $fields = ['id', 'public_id', 'topic', 'status', 'assigned_to', 'created_at'];
        if ($contact) {
            $fields = array_merge($fields, ['customer_name', 'contact_phone']);
        }
        $query = Consultation::query()->select($fields)->with('assignee:id,name');
        foreach (['status', 'topic', 'assigned_to'] as $column) {
            if (! empty($criteria[$column])) {
                $query->where($column, $criteria[$column]);
            }
        }
        if ($contact && ! empty($criteria['query_cipher'])) {
            $raw = FaNumber::normalizeDigits(trim(Crypt::decryptString($criteria['query_cipher'])));
            $needle = mb_strtolower($raw);
            $phoneNeedle = RepairIdentity::phone($raw);
            $matchedIds = [];
            foreach ((clone $query)->select(['id', 'customer_name', 'contact_phone'])->cursor() as $candidate) {
                $matchesName = str_contains(mb_strtolower((string) $candidate->customer_name), $needle);
                $matchesPhone = $phoneNeedle !== '' && str_contains(RepairIdentity::phone((string) $candidate->contact_phone), $phoneNeedle);
                if ($matchesName || $matchesPhone) {
                    $matchedIds[] = $candidate->id;
                }
            }
            $query->whereIn('id', $matchedIds ?: [0]);
        } elseif ($contact && ! empty($criteria['phone_digest'])) {
            $query->where('phone_digest', $criteria['phone_digest']);
        }

        return view('admin.consultations.index', ['consultations' => $query->latest('id')->paginate(20), 'contact' => $contact, 'criteria' => $criteria, 'staff' => $this->staff(true), 'newCount' => Consultation::query()->where('status', 'new')->count(), 'openCount' => Consultation::query()->where('status', '!=', 'closed')->count()]);
    }

    public function search(CrmSearchRequest $request)
    {
        $data = $request->validated();
        $criteria = array_diff_key($data, ['q' => true]);
        if (! empty($data['q'])) {
            Gate::authorize('consultations.contact');
            $phone = RepairIdentity::phone($data['q']);
            if (preg_match('/\A09[0-9]{9}\z/', $phone)) {
                $criteria['phone_digest'] = RepairIdentity::digest('phone', $phone);
            }
            $criteria['query_cipher'] = Crypt::encryptString($data['q']);
        }
        $request->session()->put('consultation_search', $criteria);

        return redirect()->route('admin.consultations.index');
    }

    public function show(Consultation $consultation)
    {
        $contact = Gate::allows('consultations.contact');
        $history = $contact && Gate::allows('consultations.history');
        if ($contact) {
            AdminHistory::record(AdminAction::ConsultationViewed, 'consultation', $consultation->id);
        }
        $consultation->load('assignee:id,name');

        $manage = $consultation->status !== 'closed' && Gate::allows('consultations.manage');
        $statusOptions = ['closed'];
        if ($consultation->assigned_to && in_array($consultation->status, ['new', 'contacted'], true)) {
            array_unshift($statusOptions, 'in_progress');
        }
        if ($manage && $consultation->status === 'in_progress'
            && (! $history || $consultation->entries()->where('kind', 'contact')->where('outcome', 'answered')->exists())) {
            array_unshift($statusOptions, 'contacted');
        }

        return view('admin.consultations.show', ['statusOptions' => $statusOptions, 'consultation' => $consultation, 'contact' => $contact, 'history' => $history ? $consultation->entries()->with('actor:id,name')->latest('id')->paginate(20) : null, 'staff' => $manage ? $this->staff() : collect()]);
    }

    public function update(CrmOperationRequest $request, Consultation $consultation, ConsultationManagement $management)
    {
        $management->update($request->user(), $consultation, substr($request->operation(), strlen('consultation.')), $request->validated());

        return redirect()->route('admin.consultations.show', $consultation)->with('success', 'پیگیری مشاوره ذخیره شد.');
    }

    private function staff(bool $historical = false)
    {
        $staff = AdminAccount::query()->where('is_active', true)
            ->with('user:id,name')->get()
            ->filter(fn (AdminAccount $account) => $account->isSuperAdmin()
                || ($account->owner_slot === null && ($account->isManager()
                    || ($account->role === 'staff'
                        && in_array(AdminPermission::ConsultationsView->value, $account->permissions ?? [], true)
                        && in_array(AdminPermission::ConsultationsManage->value, $account->permissions ?? [], true)))))
            ->pluck('user')->filter();

        return $historical ? $staff->concat(User::query()->whereIn('id', Consultation::query()->whereNotNull('assigned_to')->select('assigned_to'))->select('id', 'name')->get())->unique('id') : $staff;
    }
}
