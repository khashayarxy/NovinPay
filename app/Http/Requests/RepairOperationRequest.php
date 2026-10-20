<?php

namespace App\Http\Requests;

use App\Enums\RepairBrand;
use App\Enums\RepairStage;
use App\Support\FaNumber;
use App\Support\RepairIdentity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RepairOperationRequest extends FormRequest
{
    public function operation(): string
    {
        return $this->route()->defaults['operation'] ?? 'search';
    }

    public function authorize(): bool
    {
        if ($this->operation() === 'intake') {
            return $this->user()->can('repairs.intake') && $this->user()->can('repairs.view') && $this->user()->can('repairs.customer_data');
        }
        if ($this->operation() === 'search') {
            return $this->user()->can('repairs.view');
        }
        $action = $this->operation() === 'transition' ? match ($this->input('status')) {
            'inspecting' => 'inspect', 'repairing' => 'start', default => 'manage'
        } : $this->operation();

        return $this->user()->can('operate', [$this->route('repairCase'), $action]);
    }

    protected function prepareForValidation(): void
    {
        if ($this->operation() === 'intake') {
            $this->merge(['contact_phone' => RepairIdentity::phone($this->input('contact_phone'))]);
        }
        foreach (['service_cost', 'parts_cost'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => FaNumber::normalizeDigits(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        $text = ['required', 'string', 'min:2', 'max:2000'];
        $money = ['required', 'integer', 'min:0', 'max:1000000000'];
        $rules = match ($this->operation()) {
            'intake' => ['submission_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'repair_request_id' => ['nullable', 'integer', 'exists:repair_requests,id'], 'customer_name' => ['required', 'string', 'min:2', 'max:100'], 'contact_phone' => ['required', 'string', 'regex:/\A09[0-9]{9}\z/'], 'device_brand' => ['required', Rule::enum(RepairBrand::class)], 'device_model' => ['required', 'string', 'max:100'], 'device_serial' => ['nullable', 'string', 'max:120'], 'issue' => $text, 'physical_condition' => $text, 'accessories' => $text, 'received_at' => ['required', 'date_format:Y-m-d\TH:i'], 'physical_receipt_confirmed' => ['accepted']],
            'search' => ['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::enum(RepairStage::class)], 'brand' => ['nullable', Rule::enum(RepairBrand::class)], 'technician' => ['nullable', 'integer', 'exists:users,id'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), ['after_or_equal:from'])]],
            'assign' => ['technician_id' => ['required', 'integer', 'exists:users,id']],
            'transition' => ['status' => ['required', Rule::enum(RepairStage::class)]],
            'inspect' => ['findings' => $text, 'diagnosis' => $text, 'proposed_actions' => $text, 'internal_notes' => ['nullable', 'string', 'max:2000'], 'complete' => ['required', 'boolean']],
            'estimate' => ['customer_summary' => $text, 'service_cost' => $money, 'parts_cost' => $money],
            'decision' => ['estimate_id' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['approved', 'rejected'])], 'channel' => ['required', Rule::in(['phone', 'in_person', 'message', 'document'])], 'evidence' => ['required', 'string', 'min:5', 'max:1000'], 'staff_recorded_confirmation' => ['accepted']],
            'work' => ['work_performed' => $text, 'parts_replaced' => $text, 'internal_notes' => ['nullable', 'string', 'max:2000'], 'complete' => ['required', 'boolean'], 'completion_checks' => ['required_if:complete,1', 'nullable', 'string', 'min:5', 'max:2000'], 'final_result' => ['required_if:complete,1', 'nullable', 'string', 'min:5', 'max:2000']],
            'delivery' => ['recipient_name' => ['required', 'string', 'min:2', 'max:100'], 'outcome_notes' => $text, 'delivered_at' => ['required', 'date_format:Y-m-d\TH:i'], 'confirmation_method' => ['nullable', Rule::in(['staff_observation', 'message', 'document'])], 'confirmation_reference' => ['required_with:confirmation_method', 'nullable', 'string', 'min:5', 'max:1000'], 'handover_confirmed' => ['accepted'], 'amounts_confirmed' => ['sometimes', 'accepted']],
            default => [],
        };
        if (! in_array($this->operation(), ['intake', 'search'], true)) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }
        if ($this->operation() === 'delivery' && ! $this->filled('confirmation_method')) {
            $rules['confirmation_reference'][] = 'prohibited';
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['required' => 'تکمیل :attribute ضروری است.', 'accepted' => 'تأیید صریح :attribute ضروری است.', 'max' => ':attribute بیش از حد مجاز است.', 'min' => ':attribute کوتاه‌تر از حد مجاز است.', 'required_if' => 'برای تکمیل عملیات، :attribute را ثبت کنید.', 'date_format' => 'قالب زمان :attribute معتبر نیست.'];
    }

    public function attributes(): array
    {
        return ['customer_name' => 'نام مشتری', 'contact_phone' => 'شمارهٔ همراه', 'device_brand' => 'برند', 'device_model' => 'مدل دستگاه', 'device_serial' => 'سریال', 'issue' => 'مشکل گزارش‌شده', 'physical_condition' => 'وضعیت ظاهری', 'accessories' => 'لوازم همراه', 'received_at' => 'زمان دریافت', 'physical_receipt_confirmed' => 'دریافت فیزیکی', 'technician_id' => 'تکنسین', 'version' => 'نسخهٔ پرونده', 'findings' => 'مشاهدات', 'diagnosis' => 'تشخیص عیب', 'proposed_actions' => 'اقدامات پیشنهادی', 'internal_notes' => 'یادداشت داخلی', 'customer_summary' => 'شرح برآورد', 'service_cost' => 'هزینهٔ خدمات', 'parts_cost' => 'هزینهٔ قطعات', 'evidence' => 'مرجع تصمیم مشتری', 'channel' => 'کانال تصمیم', 'staff_recorded_confirmation' => 'ثبت تصمیم واقعی مشتری', 'work_performed' => 'کار انجام‌شده', 'parts_replaced' => 'قطعات توصیفی', 'completion_checks' => 'کنترل‌های پایان', 'final_result' => 'نتیجهٔ نهایی', 'recipient_name' => 'تحویل‌گیرنده', 'delivered_at' => 'زمان تحویل', 'outcome_notes' => 'نتیجهٔ تحویل', 'confirmation_method' => 'روش تأیید تحویل', 'confirmation_reference' => 'مرجع تأیید تحویل', 'handover_confirmed' => 'تحویل فیزیکی', 'amounts_confirmed' => 'مبالغ نهایی'];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new ValidationException($validator);
        }
        // No customer information, evidence or tracking reference in flashed input.
        throw new HttpResponseException(redirect()->back()->withErrors($validator));
    }
}
