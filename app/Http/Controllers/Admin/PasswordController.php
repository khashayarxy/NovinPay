<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\AdminAccount;
use App\Models\User;
use App\Support\AdminAccess;
use App\Support\AdminHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.password', ['account' => AdminAccess::account($request->user())]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255', 'current_password'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);
        $version = DB::transaction(function () use ($request, $data): int {
            $account = AdminAccount::query()->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($account->is_active && $account->session_version === $request->session()->get('admin_session_version'), 403);
            abort_unless(Hash::check($data['current_password'], $user->password), 403);
            if (Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'گذرواژهٔ تازه باید با گذرواژهٔ فعلی متفاوت باشد.']);
            }
            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
            $user->save();
            $account->session_version++;
            $account->must_change_password = false;
            $account->save();
            AdminHistory::record(AdminAction::PasswordChanged, 'user', $user->id);

            return $account->session_version;
        });
        $request->session()->regenerate();
        $request->session()->put('admin_session_version', $version);
        $request->user()->refresh();

        return redirect(AdminAccess::landingUrl($request->user()))->with('success', 'گذرواژه تغییر کرد؛ نشست‌های قبلی لغو شدند.');
    }
}
