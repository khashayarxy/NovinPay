<?php

namespace App\Services;

use App\Models\AdminAccount;
use App\Models\User;
use App\Support\AdminAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class SupportTeamAdministration
{
    public function apply(User $actor, string $action, array $input): void
    {
        abort_unless($actor->can('staff.manage') && AdminAccess::account($actor)?->isSuperAdmin(), 403);
        if ($action === 'account_add') {
            $data = Validator::make($input, ['username' => ['required', 'email', 'max:255', Rule::unique('users', 'email')], 'name' => ['required', 'string', 'max:100'], 'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->mixedCase()->numbers()], 'current_password' => ['required', 'string', 'max:255'], 'role' => ['required', Rule::in(['support', 'administrator'])]])->validate();
            app(StaffAdministration::class)->save($actor, ['name' => $data['name'], 'email' => $data['username'], 'password' => $data['password'], 'current_password' => $data['current_password'], 'is_active' => true, 'permissions' => $data['role'] === 'administrator' ? ['support.view', 'support.manage'] : ['support.view']]);

            return;
        }
        abort_unless($action === 'account_revoke', 400);
        $data = Validator::make($input, ['id' => ['required', 'regex:/\A[a-f0-9]{32}\z/D'], 'current_password' => ['required', 'string', 'max:255']])->validate();
        $target = null;
        foreach (AdminAccount::with('user')->get() as $grant) {
            if (! $grant->user) {
                continue;
            }$id = substr(hash_hmac('sha256', 'chat-operator:'.$grant->user_id, config('app.key')), 0, 32);
            if (hash_equals($id, $data['id'])) {
                $target = $grant;
                break;
            }
        }
        abort_unless($target && ($target->isSuperAdmin() || in_array('support.view', $target->permissions ?? [], true)), 404);
        app(StaffAdministration::class)->save($actor, ['name' => $target->user->name, 'email' => $target->user->email, 'password' => null, 'current_password' => $data['current_password'], 'is_active' => $target->is_active, 'permissions' => array_values(array_filter($target->permissions ?? [], fn ($permission) => ! str_starts_with($permission, 'support.')))], $target);
    }
}
