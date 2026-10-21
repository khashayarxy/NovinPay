<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialDocument extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if (array_diff(array_keys($document->getDirty()), ['charged_minor', 'paid_minor', 'version'])) {
                throw new \LogicException('Financial document identity is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Financial documents are retained.'));
    }

    protected function casts(): array
    {
        return ['charged_minor' => 'integer', 'paid_minor' => 'integer', 'source_amount_minor' => 'integer', 'source_version' => 'integer', 'version' => 'integer', 'customer_id' => 'integer', 'created_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }

    public function settlement(): string
    {
        return $this->charged_minor === 0 ? 'cleared' : ($this->paid_minor === $this->charged_minor ? 'settled' : ($this->paid_minor > 0 ? 'partial' : 'unpaid'));
    }
}
