<?php

namespace App\Http\Requests;

use App\Support\FinanceAccess;
use App\Support\FinanceInput;

class FinanceRequest extends PrivateRepairFormRequest
{
    public function operation(): string
    {
        return $this->route()->defaults['operation'] ?? '';
    }

    public function authorize(): bool
    {
        FinanceAccess::authorize($this->user(), $this->operation());

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(FinanceInput::normalize($this->all()));
    }

    public function attributes(): array
    {
        return ['amount' => 'مبلغ تومان', 'reference' => 'شناسهٔ مدرک', 'reason' => 'شرح و دلیل', 'password' => 'گذرواژهٔ فعلی', 'document' => 'شناسهٔ سند', 'version' => 'نسخهٔ سند', 'source' => 'شناسهٔ منبع', 'source_version' => 'نسخهٔ منبع', 'source_type' => 'نوع منبع', 'entry' => 'شناسهٔ ثبت اصلی', 'operation_key' => 'شناسهٔ عملیات', 'method' => 'روش ثبت', 'category' => 'دسته'];
    }

    public function rules(): array
    {
        return FinanceInput::rules($this->operation());
    }
}
