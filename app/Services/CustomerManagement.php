<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\User;
use App\Support\AdminHistory;
use App\Support\CrmAccess;
use App\Support\RepairIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomerManagement
{
    public function save(User $actor, array $data, ?Customer $target = null): Customer
    {
        CrmAccess::require($actor, CrmAccess::permissions('customer.save'));
        $phone = RepairIdentity::phone($data['contact_phone']);
        abort_unless(preg_match('/\A09[0-9]{9}\z/', $phone), 422, 'شمارهٔ همراه معتبر نیست.');
        abort_unless(! empty($data['identity_reviewed']), 422, 'بررسی هویت لازم است.');
        try {
            return DB::transaction(function () use ($actor, $data, $target, $phone): Customer {
                $digest = RepairIdentity::digest('customer-phone', $phone);
                // One non-personal key serializes same-phone duplicate checks on MySQL.
                DB::table('customer_identity_keys')->insertOrIgnore(['phone_digest' => $digest]);
                DB::table('customer_identity_keys')->where('phone_digest', $digest)->lockForUpdate()->first();
                CrmAccess::require($actor, CrmAccess::permissions('customer.save'));
                $customer = $target ? Customer::query()->lockForUpdate()->findOrFail($target->id) : new Customer;
                CrmAccess::require($actor, CrmAccess::permissions('customer.save'));
                if ($target) {
                    $this->version($customer, $data);
                }
                $identity = RepairIdentity::digest('customer-identity', CrmAccess::name($data['name']).':'.$phone);
                abort_if(Customer::query()->where('identity_digest', $identity)->when($target, fn ($q) => $q->whereKeyNot($customer->id))->exists(), 409, 'این هویت قبلاً ثبت شده است؛ پروفایل موجود را بررسی کنید.');
                $duplicate = Customer::query()->where('phone_digest', $digest)->when($target, fn ($q) => $q->whereKeyNot($customer->id))->exists();
                abort_if($duplicate && empty($data['duplicate_reviewed']), 409, 'شمارهٔ مشترک پیدا شد. ابتدا پروفایل‌های موجود و هویت مستقل را بررسی و تأیید کنید.');
                $customer->public_id ??= (string) Str::uuid();
                $customer->name = trim($data['name']);
                $customer->contact_phone = $phone;
                $customer->phone_digest = $digest;
                $customer->name_digest = RepairIdentity::digest('customer-name', CrmAccess::name($data['name']));
                $customer->identity_digest = $identity;
                $customer->is_active = (bool) $data['is_active'];
                $customer->version = $target ? $customer->version + 1 : 1;
                $customer->save();
                $this->note($actor, $customer, 'profile', $data['reason']);
                AdminHistory::record($target ? AdminAction::CustomerUpdated : AdminAction::CustomerCreated, 'customer', $customer->id, $actor->id);

                return $customer;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'پروفایل هم‌زمان یا تکراری ثبت شده؛ فهرست را تازه کنید.');
        }
    }

    public function link(User $actor, Customer $target, array $data): void
    {
        CrmAccess::require($actor, CrmAccess::permissions('customer.link'));
        CrmAccess::source($actor, $data['type']);
        abort_unless(! empty($data['identity_reviewed']), 422, 'تأیید مستقل هویت لازم است.');
        DB::transaction(function () use ($actor, $target, $data): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($target->id);
            $this->version($customer, $data);
            abort_unless($customer->is_active, 409, 'پروفایل بایگانی‌شده قابل اتصال نیست.');
            $class = CrmAccess::sourceClass($data['type']);
            $record = $class::query()->where($data['type'] === 'request' ? 'id' : 'public_id', $data['record'])->lockForUpdate()->firstOrFail();
            CrmAccess::require($actor, CrmAccess::permissions('customer.link'));
            CrmAccess::source($actor, $data['type']);
            $unlink = (bool) $data['unlink'];
            abort_unless($unlink ? (int) $record->customer_id === $customer->id : $record->customer_id === null, 409, 'اتصال تغییر کرده یا سابقه به پروفایل دیگری متصل است؛ ابتدا اتصال قبلی را بررسی کنید.');
            // Only the nullable association and concurrency version change. Never snapshots/state.
            $record->customer_id = $unlink ? null : $customer->id;
            if ($data['type'] !== 'request') {
                $record->version++;
            }
            $record->save();
            $customer->version++;
            $customer->save();
            $this->note($actor, $customer, 'link', $data['reason']);
            AdminHistory::record($unlink ? AdminAction::CustomerUnlinked : AdminAction::CustomerLinked, $data['type'], $record->id, $actor->id);
        }, 3);
    }

    public function reconcile(User $actor, Customer $source, array $data): void
    {
        CrmAccess::require($actor, CrmAccess::permissions('customer.reconcile'));
        abort_unless(! empty($data['identity_reviewed']), 422, 'تأیید مستقل هویت لازم است.');
        DB::transaction(function () use ($actor, $source, $data): void {
            $destination = Customer::query()->where('public_id', $data['destination'])->firstOrFail();
            abort_if($destination->id === $source->id, 422, 'دو پروفایل متفاوت انتخاب کنید.');
            $profiles = Customer::query()->whereIn('id', [$source->id, $destination->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $profiles[$source->id];
            CrmAccess::require($actor, CrmAccess::permissions('customer.reconcile'));
            $destination = $profiles[$destination->id];
            $this->version($source, $data);
            $this->version($destination, ['version' => $data['destination_version']]);
            abort_unless($destination->is_active, 409, 'مقصد باید فعال باشد.');
            foreach (['request', 'case', 'consultation'] as $type) {
                $class = CrmAccess::sourceClass($type);
                $records = $class::query()->where('customer_id', $source->id)->orderBy('id')->lockForUpdate()->get();
                if ($records->isNotEmpty()) {
                    CrmAccess::source($actor, $type);
                }
                foreach ($records as $record) {
                    $record->customer_id = $destination->id;
                    if ($type !== 'request') {
                        $record->version++;
                    }
                    $record->save();
                    AdminHistory::record(AdminAction::CustomerLinked, $type, $record->id, $actor->id);
                }
            }
            // Retain both encrypted identities and all original private notes.
            $source->is_active = false;
            $source->merged_into_id = $destination->id;
            $source->version++;
            $source->save();
            $destination->version++;
            $destination->save();
            foreach ([$source, $destination] as $profile) {
                $this->note($actor, $profile, 'reconciliation', $data['reason']);
                AdminHistory::record(AdminAction::CustomerReconciled, 'customer', $profile->id, $actor->id);
            }
        }, 3);
    }

    public function addNote(User $actor, Customer $target, array $data): void
    {
        CrmAccess::require($actor, CrmAccess::permissions('customer.note'));
        DB::transaction(function () use ($actor, $target, $data): void {
            $customer = Customer::query()->lockForUpdate()->findOrFail($target->id);
            $this->version($customer, $data);
            abort_unless($customer->is_active, 409, 'پروفایل بایگانی‌شده فقط خواندنی است.');
            CrmAccess::require($actor, CrmAccess::permissions('customer.note'));
            $this->note($actor, $customer, 'note', $data['body']);
            $customer->version++;
            $customer->save();
            AdminHistory::record(AdminAction::CustomerNote, 'customer', $customer->id, $actor->id);
        }, 3);
    }

    private function version(Customer $customer, array $data): void
    {
        abort_if($customer->merged_into_id || $customer->version !== (int) $data['version'], 409, 'پروفایل تغییر کرده یا تطبیق یافته است؛ آن را دوباره باز کنید.');
    }

    private function note(User $actor, Customer $customer, string $kind, string $body): void
    {
        $note = new CustomerNote;
        $note->customer_id = $customer->id;
        $note->actor_id = $actor->id;
        $note->kind = $kind;
        $note->body = $body;
        $note->created_at = now();
        $note->save();
    }
}
