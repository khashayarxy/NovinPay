<?php

namespace App\Http\Requests;

use App\Models\Consultation;
use Illuminate\Validation\Rule;

class StoreConsultation extends PrivateRepairFormRequest
{
    protected $redirectRoute = 'consultation';

    public function attributes(): array
    {
        return ['submission_token' => 'فرم مشاوره', 'customer_name' => 'نام و نام خانوادگی', 'contact_phone' => 'شمارهٔ همراه', 'topic' => 'موضوع مشاوره', 'device_info' => 'اطلاعات دستگاه', 'question' => 'شرح پرسش'];
    }

    public function rules(): array
    {
        return [
            'submission_token' => ['required', 'string', 'regex:/\\A[a-f0-9]{64}\\z/'],
            'customer_name' => ['required', 'string', 'min:2', 'max:100'],
            'contact_phone' => ['required', 'string', 'regex:/\\A09[0-9]{9}\\z/'],
            'topic' => ['required', Rule::in(array_keys(Consultation::TOPICS))],
            'device_info' => ['nullable', 'string', 'max:200'],
            'question' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
