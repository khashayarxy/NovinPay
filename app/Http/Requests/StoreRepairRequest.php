<?php

namespace App\Http\Requests;

use App\Enums\RepairBrand;
use Illuminate\Validation\Rule;

class StoreRepairRequest extends PrivateRepairFormRequest
{
    protected $redirectRoute = 'repair';

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'min:2', 'max:100'],
            'contact_phone' => ['required', 'string', 'regex:/\A09[0-9]{9}\z/'],
            'device_brand' => ['required', Rule::enum(RepairBrand::class)],
            // Models are not a fabricated catalogue: use the device's own label.
            'device_model' => ['required', 'string', 'max:100'],
            'issue' => ['required', 'string', 'min:10', 'max:2000'],
            'submission_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'تکمیل :attribute ضروری است.',
            'string' => ':attribute باید متن معتبر باشد.',
            'min' => ':attribute کوتاه‌تر از حد مجاز است.',
            'max' => ':attribute طولانی‌تر از حد مجاز است.',
            'contact_phone.regex' => 'شمارهٔ همراه ۱۱ رقمی با ۰۹ وارد کنید.',
            'device_brand' => 'یکی از برندهای PAX، NewPOS یا Newland را انتخاب کنید.',
            'submission_token' => 'فرم را دوباره باز کنید و درخواست را ارسال کنید.',
        ];
    }

    public function attributes(): array
    {
        return ['customer_name' => 'نام', 'contact_phone' => 'شماره تماس', 'device_brand' => 'برند', 'device_model' => 'مدل دستگاه', 'issue' => 'شرح مشکل', 'submission_token' => 'شناسهٔ فرم'];
    }
}
