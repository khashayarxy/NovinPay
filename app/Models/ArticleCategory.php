<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ArticleCategory extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }
}
