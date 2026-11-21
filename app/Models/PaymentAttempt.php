<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    public function statusMessage(): string
    {
        return match ($this->state) {
            'settled' => 'پرداخت تأیید و ثبت شده است.',
            'failed' => 'این تلاش پرداخت موفق نبوده است؛ می‌توانید دوباره اقدام کنید.',
            'review' => 'نتیجهٔ پرداخت به بررسی همکاران نیاز دارد؛ دوباره پرداخت نکنید.',
            default => 'نتیجهٔ پرداخت هنوز قطعی نیست؛ پیش از تلاش دوباره منتظر بررسی بمانید.',
        };
    }

    protected $guarded = ['*'];

    protected $hidden = ['merchant_digest', 'resolution_note'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'amount_rial' => 'integer', 'lease_until' => 'datetime', 'retry_at' => 'datetime', 'verified_at' => 'datetime',
            'resolved_at' => 'datetime', 'resolution_note' => 'encrypted'];
    }
}
