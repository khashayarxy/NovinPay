<?php

namespace App\Http\Requests;

use App\Enums\RepairStage;
use App\Support\FaNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class SimpleRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->input('intent') === 'handover' ? 'repairs.deliver' : 'repairs.transition';

        return $this->user()->can('repairs.view') && $this->user()->can('repairs.customer_data') && $this->user()->can($permission);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('repair_cost'))) {
            $amount = str_replace(['٬', ','], '', FaNumber::normalizeDigits(trim($this->input('repair_cost'))));
            $this->merge(['repair_cost' => $amount === '' ? null : $amount]);
        }
    }

    public function rules(): array
    {
        $intent = $this->input('intent', 'save');
        $save = $intent === 'save';
        $ready = $save && $this->input('status') === RepairStage::Ready->value;
        $shipping = $save && $this->input('delivery_method') === 'shipping';

        return [
            'version' => ['required', 'integer', 'min:1'],
            'intent' => ['sometimes', Rule::in(['save', 'settlement', 'handover'])],
            'status' => [Rule::excludeIf(! $save), 'required', Rule::enum(RepairStage::class)->except([RepairStage::Delivered, RepairStage::Returned])],
            'repair_cost' => [Rule::excludeIf(! $ready), 'nullable', 'regex:/\A[0-9]{1,10}\z/D'],
            'money_scale' => [Rule::excludeIf(! $ready || ! $this->filled('repair_cost')), 'sometimes', Rule::in(['1'])],
            'repair_notes' => [Rule::excludeIf(! $save), 'nullable', 'string', 'max:2000'],
            'settlement_method' => [Rule::excludeIf($intent !== 'settlement'), 'required', Rule::in(['in_person', 'online'])],
            'delivery_method' => [Rule::excludeIf(! $save), 'nullable', Rule::in(['in_person', 'shipping'])],
            'delivery_carrier' => [Rule::excludeIf(! $shipping), 'nullable', Rule::in(['post', 'tipax', 'courier'])],
            'clear_delivery_carrier' => [Rule::excludeIf(! $shipping), 'sometimes', 'accepted'],
            'handover_confirmed' => [Rule::excludeIf($intent !== 'handover'), 'required', 'accepted'],
            'shipment_tracking' => [Rule::excludeIf($intent !== 'handover'), 'nullable', 'string', 'max:200'],
            'current_password' => [Rule::excludeIf($intent !== 'handover'), 'nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'version' => 'نسخه پرونده', 'intent' => 'نوع عملیات', 'status' => 'وضعیت تعمیر',
            'repair_cost' => 'هزینه تعمیر', 'money_scale' => 'واحد هزینه', 'repair_notes' => 'یادداشت تعمیر',
            'settlement_method' => 'روش پرداخت ثبت‌شده', 'delivery_method' => 'روش تحویل',
            'delivery_carrier' => 'شرکت ارسال', 'clear_delivery_carrier' => 'پاک کردن شرکت ارسال',
            'handover_confirmed' => 'تأیید تحویل واقعی', 'shipment_tracking' => 'کد پیگیری ارسال',
            'current_password' => 'رمز عبور فعلی',
        ];
    }

    public function messages(): array
    {
        return [
            'status.enum' => 'وضعیت تعمیر معتبر نیست. تحویل واقعی را جداگانه ثبت کنید.',
            'repair_cost.regex' => 'هزینه تعمیر را به‌صورت عدد صحیح و به تومان وارد کنید.',
            'money_scale.in' => 'فرم را تازه کنید و مبلغ کامل را به تومان وارد کنید.',
            'delivery_carrier.in' => 'شرکت ارسال را از فهرست انتخاب کنید.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(back()->withErrors($validator));
    }
}
