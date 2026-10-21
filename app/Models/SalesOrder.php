<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    public const STATUSES = ['draft' => 'پیش‌نویس', 'reserved' => 'رزروشده', 'confirmed' => 'تأییدشده', 'fulfilled' => 'تحویل‌شده', 'cancelled' => 'لغوشده'];

    protected $guarded = ['*'];

    protected $hidden = ['fulfillment_info'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'total' => 'decimal:2', 'fulfillment_info' => 'encrypted', 'fulfilled_at' => 'datetime'];
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
