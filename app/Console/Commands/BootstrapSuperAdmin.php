<?php

namespace App\Console\Commands;

use App\Enums\AdminAction;
use App\Models\AdminAccount;
use App\Models\User;
use App\Support\AdminHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BootstrapSuperAdmin extends Command
{
    protected $signature = 'admin:bootstrap {user : Existing user ID} {--apply : Explicitly apply the verified grant}';

    protected $description = 'Verify or grant the single protected super-admin role without changing credentials';

    public function handle(): int
    {
        $email = config('admin.email');
        $user = User::query()->find($this->argument('user'));
        if (! $user || ! is_string($email) || trim($email) === '' || $user->email !== trim($email)) {
            $this->error('Existing user ID must match trusted ADMIN_EMAIL. No changes made.');

            return self::FAILURE;
        }
        $owner = AdminAccount::query()->where('owner_slot', 1)->first();
        if ($owner) {
            $valid = $owner->user_id === $user->id && $owner->isSuperAdmin() && $owner->is_active;
            $this->line($valid ? 'Protected super-admin already matches; no changes made.' : 'Protected owner already exists; refusing reassignment or recovery.');

            return $valid ? self::SUCCESS : self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Verified existing identity. Dry run only; --apply is required to grant access.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($user): void {
            $persisted = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($persisted->email !== trim(config('admin.email'))) {
                throw new RuntimeException('Trusted identity changed during bootstrap; no grant applied.');
            }
            if (AdminAccount::query()->where('user_id', $user->id)->exists()) {
                throw new RuntimeException('Existing staff grant requires an explicit reviewed transition; bootstrap refused.');
            }
            $account = new AdminAccount;
            $account->user_id = $persisted->id;
            $account->role = 'super_admin';
            $account->owner_slot = 1;
            $account->is_active = true;
            $account->permissions = [];
            $account->must_change_password = false;
            $account->save();
            AdminHistory::record(AdminAction::Bootstrap, 'user', $persisted->id, $persisted->id);
        });
        $this->info('Protected super-admin granted. Existing identity and password are unchanged.');

        return self::SUCCESS;
    }
}
