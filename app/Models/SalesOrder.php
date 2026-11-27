<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    public const STATUSES = ['draft' => 'پیش‌نویس', 'reserved' => 'رزروشده', 'confirmed' => 'تأییدشده', 'fulfilled' => 'تحویل‌شده', 'cancelled' => 'لغوشده'];

    public const DOCUMENT_STATUSES = [
        'pending_submission' => 'در انتظار ارسال',
        'pending_review' => 'در انتظار بررسی',
        'needs_revision' => 'نیاز به اصلاح',
        'approved' => 'تأیید شده',
    ];

    protected $guarded = ['*'];

    protected $hidden = ['fulfillment_info', 'telegram_chat_id', 'telegram_message_id', 'documents_staging_dir'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'total' => 'decimal:2',
            'fulfillment_info' => 'encrypted',
            'fulfilled_at' => 'datetime',
            'documents_submitted_at' => 'datetime',
            'documents_reviewed_at' => 'datetime',
            'documents_purged_at' => 'datetime',
            'telegram_message_id' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->where('is_current', true);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalesOrderEvent::class);
    }
}
