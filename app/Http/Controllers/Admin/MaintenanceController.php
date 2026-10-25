<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SiteMaintenance;
use App\Support\MaintenanceStateConflict;
use App\Support\MaintenanceStateFault;
use App\Support\SiteMaintenanceState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MaintenanceController extends Controller
{
    public function index(SiteMaintenanceState $state)
    {
        Gate::authorize('maintenance.view');
        try {
            $current = $state->read();
            $fault = false;
        } catch (MaintenanceStateFault) {
            $current = null;
            $fault = true;
        }

        return response()->view('admin.maintenance.index', compact('current', 'fault'));
    }

    public function update(Request $request, SiteMaintenance $service)
    {
        Gate::authorize('maintenance.view');
        Gate::authorize('maintenance.manage');
        $input = $request->validate([
            'version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'current_password' => ['required', 'string', 'max:255'],
            'confirm' => ['accepted'],
        ]);
        try {
            $service->change($request->user(), $input, $request->routeIs('admin.maintenance.enable'), $request->session()->get('admin_session_version'));
        } catch (MaintenanceStateConflict) {
            abort(409, 'وضعیت تغییر کرده یا درخواست دیگری در حال اجرا است؛ صفحه را تازه کنید.');
        } catch (MaintenanceStateFault $exception) {
            if ($exception->published) {
                return redirect()->route('admin.maintenance.index')->with('maintenance_warning', 'وضعیت اعمال شده است، اما تکمیل ثبت تاریخچه نیازمند بررسی است. وضعیت فعلی را کنترل کنید؛ عملیات را کورکورانه تکرار نکنید.');
            }

            return redirect()->route('admin.maintenance.index')->with('maintenance_warning', 'نوشتن امن وضعیت انجام نشد. وضعیت فعلی را بررسی کنید؛ در صورت خطای فایل از روش اضطراری مستندشده استفاده کنید.');
        }

        return redirect()->route('admin.maintenance.index')->with('success', 'وضعیت نگهداری سایت با ثبت تاریخچه تغییر کرد.');
    }

    public function preview()
    {
        Gate::authorize('maintenance.view');

        return view('admin.maintenance.preview');
    }

    public function previewPage()
    {
        Gate::authorize('maintenance.view');

        return response(SiteMaintenanceState::render(base_path()), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-NovinPay-Maintenance-Preview' => 'true',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'; sandbox",
        ]);
    }
}
