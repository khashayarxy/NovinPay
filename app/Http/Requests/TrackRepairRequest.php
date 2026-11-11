<?php

namespace App\Http\Requests;

use App\Support\FaNumber;
use App\Support\RepairTracking;

class TrackRepairRequest extends PrivateRepairFormRequest
{
    protected $redirectRoute = 'repair.track';

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $reference = $this->input('tracking_reference');
        $this->merge(['tracking_reference' => is_string($reference) ? FaNumber::normalizeDigits(trim($reference)) : '']);
    }

    public function rules(): array
    {
        return [
            'contact_phone' => ['required', 'string', 'regex:/\A09[0-9]{9}\z/'],
            'tracking_reference' => ['required', 'string', 'regex:'.RepairTracking::FORMAT],
        ];
    }

    public function messages(): array
    {
        return array_fill_keys(['required', 'string', 'regex'], 'کد پیگیری هشت‌رقمی و شماره موبایل ثبت‌شده را وارد کنید.');
    }
}
