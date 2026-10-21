<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use App\Support\CrmAccess;
use App\Support\RepairIdentity;
use Illuminate\Validation\Rule;

class CrmOperationRequest extends PrivateRepairFormRequest
{
    public function operation(): string
    {
        return $this->route()->defaults['operation'] ?? '';
    }

    public function authorize(): bool
    {
        $permissions = CrmAccess::permissions($this->operation());

        return $permissions !== [] && collect($permissions)->every(fn ($p) => $this->user()->can($p));
    }

    protected function prepareForValidation(): void
    {
        if ($this->operation() === 'customer.save') {
            $this->merge(['contact_phone' => RepairIdentity::phone($this->input('contact_phone'))]);
        }
    }

    public function rules(): array
    {
        $reason = ['required', 'string', 'min:5', 'max:1000'];
        $rules = match ($this->operation()) {
            'customer.save' => ['name' => ['required', 'string', 'min:2', 'max:100'], 'contact_phone' => ['required', 'string', 'regex:/\\A09[0-9]{9}\\z/'], 'is_active' => ['required', 'boolean'], 'identity_reviewed' => ['accepted'], 'duplicate_reviewed' => ['sometimes', 'accepted'], 'reason' => $reason],
            'customer.link' => ['type' => ['required', Rule::in(['request', 'case', 'consultation'])], 'record' => ['required', 'string', 'max:64'], 'unlink' => ['required', 'boolean'], 'identity_reviewed' => ['accepted'], 'reason' => $reason],
            'customer.reconcile' => ['destination' => ['required', 'uuid'], 'destination_version' => ['required', 'integer', 'min:1'], 'identity_reviewed' => ['accepted'], 'reason' => $reason],
            'customer.note' => ['body' => ['required', 'string', 'min:2', 'max:2000']],
            'consultation.assign' => ['assigned_to' => ['required', 'integer', 'exists:users,id']],
            'consultation.transition' => ['status' => ['required', Rule::in(array_keys(Consultation::STATUSES))]],
            'consultation.entry' => ['kind' => ['required', Rule::in(['note', 'contact'])], 'body' => ['required', 'string', 'min:5', 'max:2000'], 'channel' => ['required_if:kind,contact', 'prohibited_if:kind,note', Rule::in(['phone', 'in_person', 'external_message'])], 'outcome' => ['required_if:kind,contact', 'prohibited_if:kind,note', Rule::in(['answered', 'no_answer', 'unreachable'])]],
            default => [],
        };
        if ($this->route('customer') || $this->route('consultation')) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }
        if ($this->operation() === 'customer.link') {
            $rules['record'][] = $this->input('type') === 'request' ? 'regex:/\A[1-9][0-9]{0,18}\z/' : 'uuid';
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['required' => 'تکمیل :attribute ضروری است.', 'accepted' => 'تأیید صریح بررسی هویت ضروری است.', 'min' => ':attribute کوتاه‌تر از حد مجاز است.', 'max' => ':attribute بیش از حد مجاز است.', 'regex' => 'قالب :attribute معتبر نیست.', 'contact_phone.regex' => 'شمارهٔ همراه معتبر وارد کنید.', 'record.regex' => 'شمارهٔ درخواست آنلاین معتبر وارد کنید.', 'record.uuid' => 'شناسهٔ کامل پرونده یا مشاوره معتبر وارد کنید.'];
    }

    public function attributes(): array
    {
        return ['name' => 'نام و نام خانوادگی', 'contact_phone' => 'شمارهٔ همراه', 'is_active' => 'وضعیت پروفایل', 'reason' => 'دلیل بررسی', 'record' => 'شناسهٔ سابقه', 'type' => 'نوع سابقه', 'version' => 'نسخهٔ رکورد', 'destination' => 'شناسهٔ مقصد', 'destination_version' => 'نسخهٔ مقصد', 'body' => 'شرح خصوصی', 'assigned_to' => 'همکار مسئول', 'status' => 'مرحله', 'kind' => 'نوع یادداشت', 'channel' => 'روش ارتباط', 'outcome' => 'نتیجهٔ تماس'];
    }
}
