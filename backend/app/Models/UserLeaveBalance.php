<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLeaveBalance extends Model
{
    use HasFactory;

    protected $table = 'user_leave_balances';

    protected $fillable = [
        'user_id',
        'leave_type',
        'reference_month',
        'remaining_hours',
        'accrued_hours',
        'monthly_accrual',
    ];

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'remaining_hours' => 'decimal:2',
            'accrued_hours' => 'decimal:2',
            'monthly_accrual' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
