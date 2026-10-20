<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManageStaffRequest;
use App\Models\AdminAccount;
use App\Services\StaffAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(): View
    {
        return view('admin.staff.index', ['accounts' => AdminAccount::query()->with('user')->orderBy('id')->paginate(20)]);
    }

    public function create(): View
    {
        return view('admin.staff.form', ['staff' => null, 'permissions' => AdminPermission::cases()]);
    }

    public function store(ManageStaffRequest $request, StaffAdministration $administration): RedirectResponse
    {
        $administration->save($request->user(), $request->validated());

        return redirect()->route('admin.staff.index')->with('success', 'حساب همکار ایجاد شد؛ گذرواژهٔ اولیه را از راه امن در اختیار او بگذارید.');
    }

    public function edit(AdminAccount $staff): View
    {
        abort_unless($staff->role === 'staff' && $staff->owner_slot === null, 403);

        return view('admin.staff.form', ['staff' => $staff->load('user'), 'permissions' => AdminPermission::cases()]);
    }

    public function update(ManageStaffRequest $request, AdminAccount $staff, StaffAdministration $administration): RedirectResponse
    {
        $administration->save($request->user(), $request->validated(), $staff);

        return redirect()->route('admin.staff.index')->with('success', 'حساب به‌روزرسانی و نشست‌های قبلی آن لغو شدند.');
    }
}
