<?php

namespace App\Http\Requests;

use App\Support\CommerceAccess;
use App\Support\CommerceInput;
use Illuminate\Support\Facades\Gate;

class CommerceRequest extends PrivateRepairFormRequest
{
    public function operation(): string
    {
        return $this->route()->defaults['operation'] ?? '';
    }

    public function authorize(): bool
    {
        CommerceAccess::authorize($this->user(), $this->operation());
        if ($this->operation() === 'item.create' && $this->filled('product_id')) {
            Gate::authorize('products.view');
        }

        return true;
    }

    protected function prepareForValidation(): void {}

    public function rules(): array
    {
        return CommerceInput::rules($this->operation(), $this->route('item') !== null || $this->route('order') !== null);
    }

    public function attributes(): array
    {
        return CommerceInput::attributes();
    }
}
