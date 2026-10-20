<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Enums\RepairStage;
use App\Models\AdminAccount;
use App\Models\RepairCase;
use App\Models\RepairDelivery;
use App\Models\RepairEstimate;
use App\Models\RepairEstimateDecision;
use App\Models\RepairEvent;
use App\Models\RepairInspection;
use App\Models\RepairRequest;
use App\Models\RepairWorkEntry;
use App\Models\User;
use App\Support\AdminAccess;
use App\Support\AdminHistory;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use App\Support\RepairTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RepairOperations
{
    public static function serial(string $value): string
    {
        return strtoupper(preg_replace('/[\s\-]+/u', '', FaNumber::normalizeDigits(trim($value))) ?? '');
    }

    public static function eligibleTechnician(User $user): bool
    {
        $account = AdminAccess::account($user);

        return $account && ($account->isSuperAdmin() || in_array('repairs.inspect', $account->permissions ?? [], true) || in_array('repairs.work', $account->permissions ?? [], true));
    }

    public function intake(User $actor, array $data): RepairCase
    {
        try {
            return DB::transaction(function () use ($actor, $data): RepairCase {
                foreach (['repairs.view', 'repairs.customer_data', 'repairs.intake'] as $permission) {
                    Gate::forUser($actor)->authorize($permission);
                }
                abort_unless(in_array($data['physical_receipt_confirmed'] ?? null, [true, 1, '1', 'yes', 'on'], true), 422, 'دریافت فیزیکی باید صریحاً تأیید شود.');
                $source = ! empty($data['repair_request_id']) ? RepairRequest::query()->lockForUpdate()->findOrFail($data['repair_request_id']) : null;
                if ($source) {
                    Gate::forUser($actor)->authorize('viewCustomerData', $source);
                    abort_if(RepairCase::query()->where('repair_request_id', $source->id)->exists(), 409, 'این درخواست قبلاً پذیرش فیزیکی شده است.');
                }
                $received = RepairTime::parse($data['received_at']);
                abort_if($received->gt(now()) || $received->year < 2000, 422, 'زمان دریافت معتبر نیست.');
                $case = new RepairCase;
                $case->public_id = (string) Str::uuid();
                $case->repair_request_id = $source?->id;
                $case->tracking_reference = $source?->tracking_reference ?? 'NP-'.strtoupper(bin2hex(random_bytes(16)));
                $case->tracking_digest = RepairIdentity::digest('tracking', $case->tracking_reference);
                $case->intake_digest = RepairIdentity::digest('intake', $data['submission_token']);
                $case->phone_digest = RepairIdentity::digest('phone', $data['contact_phone']);
                foreach (['customer_name', 'contact_phone', 'device_brand', 'device_model', 'issue', 'physical_condition', 'accessories'] as $field) {
                    $case->$field = $data[$field];
                }
                $serial = self::serial($data['device_serial'] ?? '');
                $case->device_serial = $serial !== '' ? trim($data['device_serial']) : null;
                $case->serial_digest = $serial !== '' ? RepairIdentity::digest('serial', $serial) : null;
                $case->active_serial_digest = $serial !== '' ? RepairIdentity::digest('active-serial', $data['device_brand'].':'.$serial) : null;
                $case->status = RepairStage::Received;
                $case->received_at = $received;
                $case->received_by = $actor->id;
                $case->save();
                $this->event($case, $actor, AdminAction::RepairIntake, null);

                return $case;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'پذیرش تکراری است؛ درخواست، فرم یا سریال فعال را بررسی کنید.');
        }
    }

    public function update(User $actor, RepairCase $target, string $operation, array $data): RepairCase
    {
        return DB::transaction(function () use ($actor, $target, $operation, $data): RepairCase {
            $case = RepairCase::query()->lockForUpdate()->findOrFail($target->id);
            $ability = $operation === 'transition' ? match ($data['status']) {
                'inspecting' => 'inspect', 'repairing' => 'start', default => 'manage'
            } : $operation;
            Gate::forUser($actor)->authorize('operate', [$case, $ability]);
            abort_if($case->version !== (int) $data['version'], 409, 'پرونده تغییر کرده است؛ صفحه را تازه‌سازی و اطلاعات را دوباره بررسی کنید.');
            abort_if($case->status->closed(), 409, 'پرونده بسته شده و قابل تغییر نیست.');
            $before = $case->status;
            $subject = null;
            $type = null;
            switch ($operation) {
                case 'assign':
                    $technician = User::query()->lockForUpdate()->findOrFail($data['technician_id']);
                    // Lock the grant too: account activation and assignment serialize.
                    AdminAccount::query()->where('user_id', $technician->id)->lockForUpdate()->first();
                    abort_unless(self::eligibleTechnician($technician), 422, 'حساب انتخاب‌شده تکنسین فعال و مجاز نیست.');
                    abort_if($case->assigned_to === $technician->id, 409, 'این تکنسین از قبل تخصیص یافته است.');
                    $case->assigned_to = $technician->id;
                    $action = AdminAction::RepairAssigned;
                    $subject = $technician;
                    $type = 'user';
                    break;
                case 'transition':
                    $to = RepairStage::from($data['status']);
                    $allowed = match ($case->status) {
                        RepairStage::Received => [RepairStage::WaitingInspection, RepairStage::ReturnPending],
                        RepairStage::WaitingInspection => [RepairStage::Inspecting, RepairStage::ReturnPending],
                        RepairStage::Approved => [RepairStage::Repairing, RepairStage::ReturnPending],
                        RepairStage::Inspecting, RepairStage::Inspected, RepairStage::WaitingApproval, RepairStage::Repairing => [RepairStage::ReturnPending],
                        RepairStage::Repaired => [RepairStage::Ready], default => [],
                    };
                    abort_unless(in_array($to, $allowed, true), 409, 'این تغییر مرحله مجاز نیست؛ از عملیات اختصاصی استفاده کنید.');
                    if (in_array($to, [RepairStage::Inspecting, RepairStage::Repairing], true)) {
                        abort_unless($case->assigned_to, 409, 'ابتدا تکنسین را تخصیص دهید.');
                    }
                    if ($to === RepairStage::Repairing) {
                        $this->approvedEstimate($case);
                    }
                    if ($to === RepairStage::Ready) {
                        $this->completion($case);
                    }
                    $case->status = $to;
                    $action = AdminAction::RepairTransition;
                    break;
                case 'inspect':
                    abort_unless($case->assigned_to && in_array($case->status, [RepairStage::Inspecting, RepairStage::Inspected, RepairStage::WaitingApproval, RepairStage::Approved, RepairStage::Repairing], true), 409, 'بررسی در این مرحله مجاز نیست.');
                    $subject = $this->append(new RepairInspection, $actor, $case, $data, ['findings', 'diagnosis', 'proposed_actions', 'internal_notes']);
                    // New findings pause earlier approval/work; a new estimate must reference them.
                    $case->status = (bool) $data['complete'] ? RepairStage::Inspected : RepairStage::Inspecting;
                    $action = AdminAction::RepairInspection;
                    $type = 'inspection';
                    break;
                case 'estimate':
                    abort_unless(in_array($case->status, [RepairStage::Inspected, RepairStage::WaitingApproval, RepairStage::Approved], true), 409, 'ابتدا بررسی را تکمیل کنید.');
                    $inspection = $case->inspections()->latest('id')->firstOrFail();
                    $estimate = new RepairEstimate;
                    $estimate->repair_inspection_id = $inspection->id;
                    $estimate->revision = (int) $case->estimates()->max('revision') + 1;
                    $subject = $this->append($estimate, $actor, $case, $data, ['customer_summary', 'service_cost', 'parts_cost']);
                    $case->status = RepairStage::WaitingApproval;
                    $action = AdminAction::RepairEstimate;
                    $type = 'estimate';
                    break;
                case 'decision':
                    abort_unless(in_array($data['staff_recorded_confirmation'] ?? null, [true, 1, '1', 'yes', 'on'], true), 422, 'ثبت صریح تصمیم واقعی مشتری لازم است.');
                    $estimate = $case->estimates()->latest('revision')->firstOrFail();
                    abort_unless($case->status === RepairStage::WaitingApproval && $estimate->id === (int) $data['estimate_id'] && ! $estimate->decision()->exists(), 409, 'تصمیم فقط برای آخرین برآوردِ بدون تصمیم قابل ثبت است.');
                    $decision = new RepairEstimateDecision;
                    $decision->repair_estimate_id = $estimate->id;
                    $decision->actor_id = $actor->id;
                    $decision->created_at = now();
                    foreach (['decision', 'channel', 'evidence'] as $field) {
                        $decision->$field = $data[$field];
                    }
                    $decision->save();
                    $subject = $decision;
                    $case->status = $data['decision'] === 'approved' ? RepairStage::Approved : RepairStage::Inspected;
                    $action = AdminAction::RepairDecision;
                    $type = 'decision';
                    break;
                case 'work':
                    abort_unless($case->status === RepairStage::Repairing && $case->assigned_to, 409, 'کار فقط در مرحلهٔ تعمیر و با تخصیص تکنسین مجاز است.');
                    $estimate = $this->approvedEstimate($case);
                    $work = new RepairWorkEntry;
                    $work->repair_estimate_id = $estimate->id;
                    $work->is_completion = (bool) $data['complete'];
                    if ($work->is_completion) {
                        abort_unless(! empty($data['completion_checks']) && ! empty($data['final_result']), 422, 'کنترل‌های پایان و نتیجه را ثبت کنید.');
                    }
                    $subject = $this->append($work, $actor, $case, $data, ['work_performed', 'parts_replaced', 'internal_notes', 'completion_checks', 'final_result']);
                    if ($work->is_completion) {
                        $case->status = RepairStage::Repaired;
                    }
                    $action = AdminAction::RepairWork;
                    $type = 'work';
                    break;
                case 'delivery':
                    abort_if(! empty($data['confirmation_reference']) && empty($data['confirmation_method']), 422, 'مرجع تحویل به روش تأیید مشخص نیاز دارد.');
                    abort_unless(in_array($data['handover_confirmed'] ?? null, [true, 1, '1', 'yes', 'on'], true), 422, 'تحویل واقعی باید صریحاً تأیید شود.');
                    abort_unless(in_array($case->status, [RepairStage::Ready, RepairStage::ReturnPending], true), 409, 'تحویل فقط پس از کنترل‌های پایان یا تصمیم بازگرداندن مجاز است.');
                    $delivery = new RepairDelivery;
                    $delivery->outcome = $case->status === RepairStage::Ready ? 'repaired' : 'returned_without_repair';
                    if ($delivery->outcome === 'repaired') {
                        $this->completion($case);
                        $estimate = $this->approvedEstimate($case);
                        abort_unless(! empty($data['amounts_confirmed']), 422, 'مبالغ برآورد تأییدشده را بررسی و تأیید کنید.');
                        $delivery->service_cost = $estimate->service_cost;
                        $delivery->parts_cost = $estimate->parts_cost;
                    }
                    $date = RepairTime::parse($data['delivered_at']);
                    abort_if($date->gt(now()) || $date->lt($case->received_at), 422, 'زمان تحویل باید بین دریافت دستگاه و اکنون باشد.');
                    $delivery->delivered_at = $date;
                    $subject = $this->append($delivery, $actor, $case, $data, ['recipient_name', 'outcome_notes', 'confirmation_method', 'confirmation_reference']);
                    $case->status = $delivery->outcome === 'repaired' ? RepairStage::Delivered : RepairStage::Returned;
                    $case->active_serial_digest = null;
                    $action = AdminAction::RepairDelivery;
                    $type = 'delivery';
                    break;
                default: abort(404);
            }
            $case->version++;
            $case->save();
            $this->event($case, $actor, $action, $before, $type, $subject?->id);

            return $case;
        }, 3);
    }

    private function approvedEstimate(RepairCase $case): RepairEstimate
    {
        $estimate = $case->estimates()->with('decision')->latest('revision')->first();
        abort_unless($estimate && $estimate->decisionStatus() === 'approved' && (int) $estimate->repair_inspection_id === (int) $case->inspections()->max('id'), 409, 'تعمیر به آخرین برآورد و تأیید صریح متناسب با آخرین بررسی نیاز دارد.');

        return $estimate;
    }

    private function completion(RepairCase $case): void
    {
        $estimate = $this->approvedEstimate($case);
        abort_unless($case->workEntries()->where('repair_estimate_id', $estimate->id)->where('is_completion', true)->exists(), 409, 'کنترل و نتیجهٔ پایان تعمیر ثبت نشده است.');
    }

    private function append(Model $record, User $actor, RepairCase $case, array $data, array $fields): Model
    {
        $record->repair_case_id = $case->id;
        $record->actor_id = $actor->id;
        $record->created_at = now();
        foreach ($fields as $field) {
            $record->$field = $data[$field] ?? null;
        }
        $record->save();

        return $record;
    }

    private function event(RepairCase $case, User $actor, AdminAction $action, ?RepairStage $before, ?string $type = null, ?int $subjectId = null): void
    {
        $event = new RepairEvent;
        $event->repair_case_id = $case->id;
        $event->actor_id = $actor->id;
        $event->action = $action->value;
        $event->from_status = $before?->value;
        $event->to_status = $case->status->value;
        $event->subject_type = $type;
        $event->subject_id = $subjectId;
        $event->created_at = now();
        $event->save();
        AdminHistory::record($action, 'repair_case', $case->id, $actor->id);
    }
}
