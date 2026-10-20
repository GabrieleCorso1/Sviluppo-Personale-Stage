<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Capacity extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'capacities';

    protected $fillable = [
        'user_id',
        'date',
        'value',
        'billing_value',
        'sprint_id',
        'alias',
        'is_capacity_per_day',
        'is_statistical',
        'is_dailydone',
        'note',
        'request_status',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'float',
            'billing_value' => 'float',
            'is_capacity_per_day' => 'boolean',
            'is_statistical' => 'boolean',
            'is_dailydone' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class, 'sprint_id');
    }
}