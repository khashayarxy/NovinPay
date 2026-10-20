<?php

namespace App\Http\Requests;

class TrackRepairRequest extends PrivateRepairFormRequest
{
    protected $redirectRoute = 'repair.track';

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $reference = $this->input('tracking_reference');
        $this->merge(['tracking_reference' => is_string($reference) ? strtoupper(trim($reference)) : '']);
    }

    public function rules(): array
    {
        return [
            'contact_phone' => ['required', 'string', 'regex:/\A09[0-9]{9}\z/'],
            'tracking_reference' => ['required', 'string', 'regex:/\ANP-[A-F0-9]{32}\z/'],
        ];
    }

    public function messages(): array
    {
        return array_fill_keys(['required', 'string', 'regex'], 'کد پیگیری کامل و شمارهٔ همراه ثبت‌شده را وارد کنید.');
    }
}
