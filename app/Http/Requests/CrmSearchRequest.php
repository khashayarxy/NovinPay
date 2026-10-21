<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use Illuminate\Validation\Rule;

class CrmSearchRequest extends PrivateRepairFormRequest
{
    protected function prepareForValidation(): void {}

    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('admin.customers.*') ? 'customers.view' : 'consultations.view');
    }

    public function rules(): array
    {
        return $this->routeIs('admin.customers.*')
            ? ['q' => ['nullable', 'string', 'max:120'], 'active' => ['nullable', 'in:0,1']]
            : ['q' => ['nullable', 'string', 'max:64'], 'status' => ['nullable', Rule::in(array_keys(Consultation::STATUSES))], 'topic' => ['nullable', Rule::in(array_keys(Consultation::TOPICS))], 'assigned_to' => ['nullable', 'integer', 'exists:users,id']];
    }
}
