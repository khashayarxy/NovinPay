<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductFeedback extends Model
{
    protected $table = 'product_feedback';

    protected $hidden = ['customer_account_id', 'review_owner_id'];

    protected function casts(): array
    {
        return ['verified_buyer' => 'boolean', 'rating' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ProductFeedbackReport::class);
    }
}
