<?php

namespace App\Http\Requests;

use App\Support\FaNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class SimpleRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('repairs.view') && $this->user()->can('repairs.customer_data') && $this->user()->can('repairs.transition');
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('settled'), [true, 1, '1', 'yes', 'on'], true)) {
            $this->merge(['settled' => '1']);
        }
        if (is_string($this->input('repair_cost'))) {
            $this->merge(['repair_cost' => str_replace(['٬', ','], '', FaNumber::normalizeDigits(trim($this->input('repair_cost'))))]);
        }
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['received', 'repairing', 'ready', 'delivered', 'return_pending'])],
            'repair_cost' => ['required', 'string', 'max:15'],
            'money_scale' => ['required', Rule::in(['1', '1000'])],
            'repair_notes' => ['nullable', 'string', 'max:2000'],
            'settled' => ['sometimes', 'accepted'],
            'settlement_method' => ['required_if:settled,1', 'nullable', Rule::in(['in_person', 'online'])],
            'delivery_method' => ['required', Rule::in(['in_person', 'shipping'])],
            'delivery_carrier' => ['required_if:delivery_method,shipping', 'nullable', Rule::in(['post', 'tipax', 'courier'])],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(back()->withErrors($validator));
    }
}
