<?php

namespace App\Http\Requests;

use App\Enums\RepairBrand;
use App\Models\RepairRequest;
use App\Support\RepairIdentity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRepairRequest extends PrivateRepairFormRequest
{
    protected $redirectRoute = 'repair';

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $account = Auth::guard('customer')->user();
        $merge = ['contact_phone' => $account?->phone];

        if ($this->input('other_person_handover') === '0') {
            $merge['responsive_phone'] = null;
        } elseif ($this->filled('responsive_phone') && is_string($this->input('responsive_phone'))) {
            $merge['responsive_phone'] = RepairIdentity::phone($this->input('responsive_phone'));
        }

        if ($this->filled('pickup_notes') && is_string($this->input('pickup_notes'))) {
            $merge['pickup_notes'] = trim(preg_replace('/\s+/u', ' ', $this->input('pickup_notes')) ?? '');
        }

        $this->merge($merge);
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
            'device_serial' => ['nullable', 'string', 'max:100'],
            'accessories' => ['sometimes', 'array', 'max:3'],
            'accessories.*' => ['required', 'string', 'distinct', Rule::in(array_keys(RepairRequest::ACCESSORIES))],
            'device_photo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:8192'],
            'device_photos' => ['nullable', 'array'],
            'device_photos.front' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:8192'],
            'device_photos.back' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:8192'],
            'device_photos.right' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:8192'],
            'device_photos.left' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:8192'],
            'province' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'handover_method' => ['nullable', 'string', Rule::in(array_keys(RepairRequest::HANDOVER_METHODS))],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'pickup_notes' => ['nullable', 'string', 'max:300'],
            'pickup_latitude' => ['nullable', 'numeric', 'between:-85,85'],
            'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'other_person_handover' => ['nullable', 'boolean'],
            'responsive_phone' => ['nullable', 'string', 'regex:/\A09[0-9]{9}\z/'],
            'submission_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = $this->input('handover_method');
            if ($method !== 'doorstep_pickup') {
                return;
            }

            $province = trim((string) $this->input('province', ''));
            $city = trim((string) $this->input('city', ''));
            $zones = config('delivery.courier_zones', []);
            $allowedCities = is_array($zones[$province] ?? null) ? $zones[$province] : [];

            if ($province === '' || $city === '' || ! in_array($city, $allowedCities, true)) {
                $validator->errors()->add('handover_method', 'تحویل در محل فقط برای شهرهای تحت پوشش پیک نوین‌پی فعال است.');
            }

            if (mb_strlen(trim((string) $this->input('pickup_address', ''))) < 10) {
                $validator->errors()->add('pickup_address', 'برای تحویل در محل، نشانی دقیق (حداقل ۱۰ کاراکتر) را وارد کنید.');
            }
        });
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
            'responsive_phone.regex' => 'شماره تماس پاسخگو باید ۱۱ رقمی و با ۰۹ شروع شود.',
            'device_brand' => 'یکی از برندهای مجاز دستگاه را انتخاب کنید.',
            'handover_method.in' => 'روش تحویل دستگاه معتبر نیست.',
            'submission_token' => 'فرم را دوباره باز کنید و درخواست را ارسال کنید.',
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_name' => 'نام',
            'contact_phone' => 'شماره موبایل',
            'responsive_phone' => 'شماره تماس پاسخگو',
            'device_brand' => 'برند',
            'device_model' => 'مدل دستگاه',
            'issue' => 'شرح مشکل دستگاه',
            'province' => 'استان',
            'city' => 'شهر',
            'handover_method' => 'روش تحویل دستگاه',
            'pickup_address' => 'نشانی تحویل در محل',
            'pickup_notes' => 'توضیحات برای پیک',
            'submission_token' => 'شناسهٔ فرم',
        ];
    }
}
