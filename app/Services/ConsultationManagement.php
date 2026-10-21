<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Enums\AdminPermission;
use App\Models\AdminAccount;
use App\Models\Consultation;
use App\Models\ConsultationEntry;
use App\Models\User;
use App\Support\AdminAccess;
use App\Support\AdminHistory;
use App\Support\CrmAccess;
use Illuminate\Support\Facades\DB;

final class ConsultationManagement
{
    public function update(User $actor, Consultation $target, string $operation, array $data): void
    {
        CrmAccess::require($actor, CrmAccess::permissions('consultation.'.$operation));
        DB::transaction(function () use ($actor, $target, $operation, $data): void {
            $consultation = Consultation::query()->lockForUpdate()->findOrFail($target->id);
            CrmAccess::require($actor, CrmAccess::permissions('consultation.'.$operation));
            abort_if($consultation->version !== (int) $data['version'] || $consultation->status === 'closed', 409, 'درخواست تغییر کرده یا بسته شده است؛ فهرست را تازه کنید.');
            $action = match ($operation) {
                'assign' => AdminAction::ConsultationAssigned,
                'transition' => AdminAction::ConsultationTransition,
                'entry' => AdminAction::ConsultationEntry,
                default => abort(404),
            };
            $beforeStatus = $consultation->status;
            if ($operation === 'assign') {
                $staff = User::query()->lockForUpdate()->findOrFail($data['assigned_to']);
                AdminAccount::query()->where('user_id', $staff->id)->lockForUpdate()->first();
                abort_unless(AdminAccess::allows($staff, AdminPermission::ConsultationsManage) && AdminAccess::allows($staff, AdminPermission::ConsultationsView), 422, 'همکار باید فعال و دارای مجوز مدیریت مشاوره باشد.');
                abort_if((int) $consultation->assigned_to === $staff->id, 409, 'همکار قبلاً تخصیص یافته است.');
                $consultation->assigned_to = $staff->id;
            } elseif ($operation === 'transition') {
                $allowed = match ($consultation->status) {
                    'new' => ['in_progress', 'closed'],
                    'in_progress' => ['contacted', 'closed'],
                    'contacted' => ['in_progress', 'closed'],
                    default => [],
                };
                abort_unless(in_array($data['status'], $allowed, true), 409, 'تغییر مرحله مجاز نیست.');
                if ($data['status'] === 'in_progress') {
                    abort_unless($consultation->assigned_to, 409, 'ابتدا همکار مسئول را مشخص کنید.');
                }
                if ($data['status'] === 'contacted') {
                    // A status label must never invent a successful contact.
                    abort_unless($consultation->entries()->where('kind', 'contact')->where('outcome', 'answered')->exists(), 409, 'ابتدا تماس واقعاً برقرارشده را با توضیح ثبت کنید.');
                }
                $consultation->status = $data['status'];
            } else {
                $entry = new ConsultationEntry;
                $entry->consultation_id = $consultation->id;
                $entry->actor_id = $actor->id;
                foreach (['kind', 'body', 'channel', 'outcome'] as $field) {
                    $entry->$field = $data[$field] ?? null;
                }
                $entry->created_at = now();
                $entry->save();
            }
            $consultation->version++;
            $consultation->save();
            if ($operation !== 'entry') {
                $event = new ConsultationEntry;
                $event->consultation_id = $consultation->id;
                $event->actor_id = $actor->id;
                $event->kind = 'event';
                $event->body = $operation === 'assign'
                    ? 'تخصیص به شناسهٔ همکار '.$consultation->assigned_to
                    : Consultation::STATUSES[$beforeStatus].' ← '.Consultation::STATUSES[$consultation->status];
                $event->created_at = now();
                $event->save();
            }
            AdminHistory::record($action, 'consultation', $consultation->id, $actor->id);
        }, 3);
    }
}
