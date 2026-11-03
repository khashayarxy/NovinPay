<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\AdminAccount;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Support\AdminHistory;
use App\Support\CrmAccess;
use App\Support\RepairIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CustomerAccountLink
{
    public function link(User $actor, string $accountId, string $customerId, string $password): void
    {
        DB::transaction(function () use ($actor, $accountId, $customerId, $password): void {
            $grant = AdminAccount::query()->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            abort_if($grant->must_change_password, 403);
            $persisted = User::query()->lockForUpdate()->findOrFail($actor->id);
            CrmAccess::require($persisted, ['customers.view', 'customers.contact', 'customers.link']);
            abort_unless(Hash::check($password, $persisted->password), 403);
            $customer = Customer::query()->where('public_id', $customerId)->lockForUpdate()->firstOrFail();
            $account = CustomerAccount::query()->where('public_id', $accountId)->lockForUpdate()->firstOrFail();
            abort_unless($account->is_active && $account->customer_id === null && $customer->is_active && $customer->merged_into_id === null, 409);
            abort_unless(hash_equals(RepairIdentity::digest('customer-account-phone', $customer->contact_phone), $account->phone_digest), 409, 'شماره‌ها یکسان نیستند؛ ابتدا هویت و اطلاعات تماس را بررسی کنید.');
            $account->customer_id = $customer->id;
            $account->session_version++;
            $account->save();
            AdminHistory::record(AdminAction::CustomerLinked, 'customer_account', $account->id, $actor->id);
        }, 3);
    }
}
