<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\RepairRequest;
use App\Models\User;
use App\Support\AdminAccess;

class RepairRequestPolicy
{
    public function view(User $user, RepairRequest $repairRequest): bool
    {
        return $repairRequest->exists && AdminAccess::allows($user, AdminPermission::RepairsView);
    }

    public function viewCustomerData(User $user, RepairRequest $repairRequest): bool
    {
        return $this->view($user, $repairRequest) && AdminAccess::allows($user, AdminPermission::RepairsCustomerData);
    }
}
