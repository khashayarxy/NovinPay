<?php

namespace App\Models;

use App\Enums\AdminAction;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AdminActivity extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['action' => AdminAction::class, 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity history is append-only.'));
        static::deleting(fn () => throw new LogicException('Activity history is append-only.'));
    }
}
