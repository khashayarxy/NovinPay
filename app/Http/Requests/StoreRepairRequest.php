<?php

namespace App\Http\Requests;

use App\Enums\RepairBrand;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreRepairRequest extends PrivateRepairFormRequest
{
    protected $redirectRoute = 'repair';

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $account = Auth::guard('customer')->user();
        $this->merge(['contact_phone' => $account?->phone]);
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'min:2', 'max:100'],
            'contact_phone' => ['required', 'string', 'regex:/\A09[0-9]{9}\z/'],
            'device_brand' => ['required', Rule::enum(RepairBrand::class)],
            // Models are not a fabricated catalogue: use the device's own label.
            'device_model' => ['required', 'string', 'max:100'],
            'issue' => ['required', 'string', 'min:10', 'max:2000'],
            // Accept the older form while a customer may still have it open.
            'additional_description' => ['nullable', 'string', 'max:1000'],
            'submission_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'تکمیل :attribute ضروری است.',
            'string' => ':attribute باید متن معتبر باشد.',
            'min' => ':attribute کوتاه‌تر از حد مجاز است.',
            'issue.min' => 'شرح مشکل دستگاه باید دست‌کم ۱۰ کاراکتر باشد.',
            'max' => ':attribute طولانی‌تر از حد مجاز است.',
            'issue.max' => 'شرح مشکل دستگاه نباید بیشتر از ۲۰۰۰ کاراکتر باشد.',
            'contact_phone.regex' => 'شماره موبایل ۱۱ رقمی با ۰۹ وارد کنید.',
            'device_brand' => 'یکی از برندهای PAX، NewPOS یا Newland را انتخاب کنید.',
            'submission_token' => 'فرم را دوباره باز کنید و درخواست را ارسال کنید.',
        ];
    }

    public function attributes(): array
    {
        return ['customer_name' => 'نام', 'contact_phone' => 'شماره موبایل', 'device_brand' => 'برند', 'device_model' => 'مدل دستگاه', 'issue' => 'شرح مشکل دستگاه', 'submission_token' => 'شناسهٔ فرم'];
    }
}
