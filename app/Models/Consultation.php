<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends Model
{
    public const TOPICS = ['purchase' => 'مشاورهٔ خرید', 'repair' => 'پرسش دربارهٔ تعمیر', 'service' => 'خدمات کارتخوان', 'other' => 'سایر پرسش‌ها'];

    public const STATUSES = ['new' => 'جدید', 'in_progress' => 'در حال بررسی', 'contacted' => 'تماس برقرار شده', 'closed' => 'بسته شده'];

    protected $guarded = ['*'];

    protected $visible = ['public_id', 'topic', 'status'];

    protected function casts(): array
    {
        return ['customer_name' => 'encrypted', 'contact_phone' => 'encrypted', 'device_info' => 'encrypted', 'question' => 'encrypted', 'version' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ConsultationEntry::class);
    }
}
