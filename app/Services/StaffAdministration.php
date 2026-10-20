<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Enums\AdminPermission;
use App\Models\AdminAccount;
use App\Models\User;
use App\Support\AdminHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StaffAdministration
{
    public function save(User $actor, array $data, ?AdminAccount $target = null): AdminAccount
    {
        return DB::transaction(function () use ($actor, $data, $target): AdminAccount {
            $owner = AdminAccount::query()->where('user_id', $actor->id)->lockForUpdate()->first();
            abort_unless($owner?->isSuperAdmin() && $owner->is_active, 403);
            $persistedActor = User::query()->findOrFail($actor->id);
            if (! Hash::check($data['current_password'], $persistedActor->password)) {
                throw ValidationException::withMessages(['current_password' => 'گذرواژهٔ فعلی مدیر کل صحیح نیست.']);
            }
            $permissions = array_values($data['permissions'] ?? []);
            foreach ($permissions as $permission) {
                if (! is_string($permission) || AdminPermission::tryFrom($permission) === null) {
                    throw ValidationException::withMessages(['permissions' => 'مجوز انتخاب‌شده معتبر نیست.']);
                }
            }
            foreach ([[AdminPermission::ProductsManage, AdminPermission::ProductsView], [AdminPermission::RepairsCustomerData, AdminPermission::RepairsView]] as [$extra, $base]) {
                if (in_array($extra->value, $permissions, true) && ! in_array($base->value, $permissions, true)) {
                    throw ValidationException::withMessages(['permissions' => 'مجوز «'.$extra->label().'» به «'.$base->label().'» نیاز دارد.']);
                }
            }
            $creating = $target === null;
            $account = $creating ? new AdminAccount : AdminAccount::query()->lockForUpdate()->findOrFail($target->id);
            // Owner/self accounts cannot be updated, demoted, replaced or deactivated here.
            abort_if(! $creating && ($account->role !== 'staff' || $account->owner_slot !== null || $account->user_id === $actor->id), 403);
            $user = $creating ? new User : User::query()->lockForUpdate()->findOrFail($account->user_id);
            $beforePermissions = $account->permissions ?? [];
            $beforeActive = $account->is_active;
            $user->name = $data['name'];
            $user->email = $data['email'];
            if (! empty($data['password'])) {
                $user->password = $data['password'];
                $account->must_change_password = true;
            }
            $user->remember_token = Str::random(60);
            $user->save();
            $account->user_id = $user->id;
            $account->role = 'staff';
            $account->owner_slot = null;
            $account->is_active = (bool) $data['is_active'];
            $account->permissions = $permissions;
            $account->session_version = $creating ? 1 : $account->session_version + 1;
            $account->save();
            AdminHistory::record($creating ? AdminAction::StaffCreated : AdminAction::StaffUpdated, 'user', $user->id, $actor->id);
            if (! $creating && $permissions !== $beforePermissions) {
                AdminHistory::record(AdminAction::PermissionsChanged, 'user', $user->id, $actor->id);
            }
            if ($creating || $beforeActive !== $account->is_active) {
                AdminHistory::record($account->is_active ? AdminAction::Activated : AdminAction::Deactivated, 'user', $user->id, $actor->id);
            }
            if (! $creating && ! empty($data['password'])) {
                AdminHistory::record(AdminAction::PasswordReset, 'user', $user->id, $actor->id);
            }

            return $account;
        });
    }
}
