<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Article extends Model
{
    protected $guarded = ['*'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['draft_version_id' => 'integer', 'published_version_id' => 'integer', 'version' => 'integer', 'published_at' => 'datetime', 'unpublished_at' => 'datetime'];
    }

    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'draft_version_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'published_version_id');
    }
}
