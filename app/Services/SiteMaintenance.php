<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\AdminAccount;
use App\Models\User;
use App\Support\AdminHistory;
use App\Support\MaintenanceStateFault;
use App\Support\SiteMaintenanceState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SiteMaintenance
{
    public function __construct(private SiteMaintenanceState $state) {}

    public function change(User $actor, array $input, bool $enabled, mixed $sessionVersion): void
    {
        $published = false;
        try {
            DB::transaction(function () use ($actor, $input, $enabled, $sessionVersion, &$published): void {
                $account = AdminAccount::query()->where('user_id', $actor->id)->lockForUpdate()->first();
                $persisted = User::query()->lockForUpdate()->findOrFail($actor->id);
                Gate::forUser($persisted)->authorize('maintenance.view');
                Gate::forUser($persisted)->authorize('maintenance.manage');
                abort_unless($account && ! $account->must_change_password && $account->session_version === $sessionVersion, 403);
                if (! Hash::check($input['current_password'], $persisted->password)) {
                    throw ValidationException::withMessages(['current_password' => 'گذرواژهٔ فعلی معتبر نیست.']);
                }
                // No filesystem state is touched until persisted authorization
                // and password checks pass under the existing staff row lock.
                $html = $enabled ? SiteMaintenanceState::render(base_path()) : '';
                $this->state->change($input['version'], $enabled, $actor->id, $html);
                $published = true;
                AdminHistory::record($enabled ? AdminAction::MaintenanceEnabled : AdminAction::MaintenanceDisabled, 'public_website', null, $actor->id);
            }, attempts: 1);
        } catch (\Throwable $exception) {
            if ($published || ($exception instanceof MaintenanceStateFault && $exception->published)) {
                // Filesystem audit is authoritative across a DB commit failure.
                // Do not undo another administrator's newer state or claim PASS.
                Log::error('Maintenance state applied; audit mirror/completion requires inspection. No request payload recorded.');
                throw new MaintenanceStateFault('state_applied_audit_pending', true);
            }
            throw $exception;
        }
    }
}
