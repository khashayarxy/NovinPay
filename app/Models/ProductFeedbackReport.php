<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFeedbackReport extends Model
{
    protected $fillable = ['customer_account_id', 'reason'];
}
