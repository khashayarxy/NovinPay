<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFeedbackHelpfulVote extends Model
{
    protected $table = 'product_feedback_helpful_votes';

    protected $guarded = ['*'];

    protected $hidden = ['customer_account_id', 'voter_digest'];

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(ProductFeedback::class, 'product_feedback_id');
    }
}
