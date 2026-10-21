<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ArticleVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['source', 'blocks'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'revision' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Article versions are immutable.'));
        self::deleting(fn () => throw new LogicException('Article versions are immutable.'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }
}
