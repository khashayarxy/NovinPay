<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class FinanceAccess
{
    public static function authorize(User $actor, string $action): void
    {
        $permissions = match ($action) {
            'issue' => ['finance.charge'],
            'receipt' => ['finance.receive', 'finance.details'],
            'expense' => ['finance.expenses', 'finance.details'],
            'credit', 'debit', 'reverse' => ['finance.correct', 'finance.details'],
            default => [],
        };
        abort_if($permissions === [], 403);
        foreach (['finance.view', ...$permissions] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
    }

    public static function source(User $actor, string $type): void
    {
        Gate::forUser($actor)->authorize(match ($type) {
            'order' => 'orders.view', 'repair' => 'repairs.view', default => 'finance.invalid',
        });
    }
}
