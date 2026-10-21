<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class CommerceHistory extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Business history is append-only.'));
        static::deleting(fn () => throw new LogicException('Business history is append-only.'));
    }
}
