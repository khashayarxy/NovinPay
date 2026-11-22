<?php

namespace App\Events;

use App\Models\FinancialDocument;
use App\Models\PaymentTransaction;
use App\Models\RepairCase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RepairPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RepairCase $repairCase,
        public readonly FinancialDocument $document,
        public readonly PaymentTransaction $transaction,
    ) {}
}
