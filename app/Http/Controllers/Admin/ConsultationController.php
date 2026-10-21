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
use App\Support\AdminAccess;
use App\Support\AdminHistory;
use App\Support\RepairIdentity;
use Illuminate\Http\Request;
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
        if ($contact && ! empty($criteria['phone_digest'])) {
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
            $criteria['phone_digest'] = RepairIdentity::digest('phone', RepairIdentity::phone($data['q']));
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

        return view('admin.consultations.show', ['consultation' => $consultation, 'contact' => $contact, 'history' => $history ? $consultation->entries()->with('actor:id,name')->latest('id')->paginate(20) : null, 'staff' => Gate::allows('consultations.manage') ? $this->staff() : collect()]);
    }

    public function update(CrmOperationRequest $request, Consultation $consultation, ConsultationManagement $management)
    {
        $management->update($request->user(), $consultation, substr($request->operation(), strlen('consultation.')), $request->validated());

        return redirect()->route('admin.consultations.show', $consultation)->with('success', 'عملیات مشاوره ثبت شد؛ هیچ پیامی ارسال نشده است.');
    }

    private function staff(bool $historical = false)
    {
        $staff = User::query()->whereIn('id', AdminAccount::query()->where('is_active', true)->select('user_id'))->select('id', 'name')->get()->filter(fn ($u) => AdminAccess::allows($u, AdminPermission::ConsultationsView) && AdminAccess::allows($u, AdminPermission::ConsultationsManage));

        return $historical ? $staff->merge(User::query()->whereIn('id', Consultation::query()->whereNotNull('assigned_to')->select('assigned_to'))->select('id', 'name')->get())->unique('id') : $staff;
    }
}
