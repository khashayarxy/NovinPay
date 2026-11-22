<?php

namespace App\Events;

use App\Models\FinancialDocument;
use App\Models\RepairCase;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RepairPaymentLinkGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RepairCase $repairCase,
        public readonly FinancialDocument $document,
        public readonly string $paymentUrl,
        public readonly ?User $actor = null,
    ) {}
}
