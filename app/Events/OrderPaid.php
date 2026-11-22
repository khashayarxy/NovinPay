<?php

namespace App\Events;

use App\Models\FinancialDocument;
use App\Models\PaymentTransaction;
use App\Models\SalesOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SalesOrder $order,
        public readonly FinancialDocument $document,
        public readonly PaymentTransaction $transaction,
    ) {}
}
