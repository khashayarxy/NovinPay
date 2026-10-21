<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['serialized' => 'boolean', 'on_hand' => 'integer', 'reserved' => 'integer', 'dispatched' => 'integer', 'version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(InventoryUnit::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getAvailableAttribute(): int
    {
        return $this->on_hand - $this->reserved;
    }
}
