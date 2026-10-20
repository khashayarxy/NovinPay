<?php

namespace App\Http\Requests;

use App\Support\RepairIdentity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

abstract class PrivateRepairFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['contact_phone' => RepairIdentity::phone($this->input('contact_phone'))]);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new ValidationException($validator);
        }

        // Never flash customer data, tracking credentials or issue text to sessions.
        throw new HttpResponseException(redirect($this->getRedirectUrl())->withErrors($validator));
    }
}
