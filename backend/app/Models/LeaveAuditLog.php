<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveAuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'admin_id',
        'leave_type',
        'reference_month',
        'field_name',
        'old_value',
        'new_value',
    ];

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'old_value' => 'decimal:2',
            'new_value' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
