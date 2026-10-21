<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\RepairCase;
use App\Models\RepairRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CrmAccess
{
    public static function require(User $actor, array $permissions): void
    {
        foreach ($permissions as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
    }

    public static function permissions(string $operation): array
    {
        return match ($operation) {
            'customer.save' => ['customers.view', 'customers.contact', 'customers.manage'],
            'customer.link' => ['customers.view', 'customers.contact', 'customers.link'],
            'customer.reconcile' => ['customers.view', 'customers.contact', 'customers.manage', 'customers.link', 'customers.reconcile', 'customers.notes'],
            'customer.note' => ['customers.view', 'customers.contact', 'customers.manage', 'customers.notes'],
            'consultation.assign', 'consultation.transition' => ['consultations.view', 'consultations.manage'],
            'consultation.entry' => ['consultations.view', 'consultations.contact', 'consultations.manage', 'consultations.history'],
            default => [],
        };
    }

    public static function sourceClass(string $type): string
    {
        return match ($type) {
            'request' => RepairRequest::class,
            'case' => RepairCase::class,
            'consultation' => Consultation::class,
            default => abort(422, 'نوع سابقه معتبر نیست.'),
        };
    }

    public static function source(User $actor, string $type): void
    {
        self::require($actor, $type === 'consultation' ? ['consultations.view', 'consultations.contact'] : ['repairs.view', 'repairs.customer_data']);
    }

    public static function name(string $name): string
    {
        return preg_replace('/\s+/u', ' ', str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], trim(mb_strtolower($name)))) ?? '';
    }
}
