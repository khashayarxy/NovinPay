<?php

namespace App\Http\Requests;

use App\Enums\AdminPermission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ManageStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('staff.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->route('staff')?->user_id)],
            'password' => [$this->isMethod('post') ? 'required' : 'nullable', 'string', 'max:255', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'current_password' => ['required', 'string', 'max:255', 'current_password'],
            'is_active' => ['required', 'boolean'],
            'permissions' => ['nullable', 'array', 'max:'.count(AdminPermission::cases())],
            'permissions.*' => ['required', 'string', 'distinct', Rule::enum(AdminPermission::class)],
            'role' => ['prohibited'], 'owner_slot' => ['prohibited'], 'user_id' => ['prohibited'],
            'session_version' => ['prohibited'], 'must_change_password' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['current_password.current_password' => 'گذرواژهٔ فعلی مدیر کل صحیح نیست.', 'permissions.*' => 'مجوز انتخاب‌شده معتبر نیست.', 'prohibited' => 'ارسال :attribute مجاز نیست.'];
    }

    public function attributes(): array
    {
        return ['name' => 'نام همکار', 'email' => 'ایمیل', 'password' => 'گذرواژهٔ همکار', 'current_password' => 'گذرواژهٔ فعلی مدیر کل', 'permissions' => 'مجوزها', 'is_active' => 'فعال بودن حساب'];
    }
}
