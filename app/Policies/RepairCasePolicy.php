<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\RepairCase;
use App\Models\User;
use App\Support\AdminAccess;

class RepairCasePolicy
{
    public function view(User $user, RepairCase $repairCase): bool
    {
        return $repairCase->exists && AdminAccess::allows($user, AdminPermission::RepairsView);
    }

    public function viewCustomerData(User $user, RepairCase $repairCase): bool
    {
        return $this->view($user, $repairCase) && AdminAccess::allows($user, AdminPermission::RepairsCustomerData);
    }

    public function operate(User $user, RepairCase $repairCase, string $operation): bool
    {
        $permission = match ($operation) {
            'assign' => AdminPermission::RepairsAssign, 'inspect' => AdminPermission::RepairsInspect,
            'estimate' => AdminPermission::RepairsEstimate, 'decision' => AdminPermission::RepairsApprove,
            'work', 'start' => AdminPermission::RepairsWork, 'manage' => AdminPermission::RepairsManage,
            'delivery' => AdminPermission::RepairsDeliver, 'print' => AdminPermission::RepairsPrint,
            default => null,
        };
        if (! $permission || ! $this->view($user, $repairCase) || ! AdminAccess::allows($user, $permission)) {
            return false;
        }
        if (! in_array($operation, ['assign', 'manage'], true) && ! $this->viewCustomerData($user, $repairCase)) {
            return false;
        }

        return ! in_array($operation, ['inspect', 'work', 'start'], true) || AdminAccess::account($user)?->isSuperAdmin() || $repairCase->assigned_to === $user->id;
    }
}
