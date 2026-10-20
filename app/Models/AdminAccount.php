<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAccount extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['session_version'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_active' => 'boolean', 'must_change_password' => 'boolean', 'session_version' => 'integer', 'owner_slot' => 'integer', 'user_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin' && $this->owner_slot === 1;
    }
}
