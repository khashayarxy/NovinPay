<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SupportAccessController extends Controller
{
    public function confirm(Request $request)
    {
        abort_unless(config('support_chat.enabled'), 404);

        return view('admin.support-confirm');
    }

    public function authenticate(Request $request)
    {
        abort_unless(config('support_chat.enabled'), 404);
        $data = $request->validate(['current_password' => ['required', 'string', 'current_password', 'max:255']]);
        DB::transaction(function () use ($request, $data): void {
            $grant = AdminAccount::query()->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($grant->is_active && ! $grant->must_change_password && $grant->session_version === $request->session()->get('admin_session_version') && $user->can('support.view') && Hash::check($data['current_password'], $user->password), 403);
        });
        $request->session()->regenerate();
        $request->session()->put('support_authentication_nonce', bin2hex(random_bytes(16)));
        $request->session()->put('support_authenticated_at', time());
        $request->session()->forget('support_chat_window');

        return redirect('/support-chat/operator/');
    }
}
