<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoursDistribution extends Model
{
    use HasFactory;

    protected $table = 'hours_distribution';

    protected $fillable = [
        'user_id',
        'alias',
        'start_hour',
        'end_hour',
        'is_holiday',
    ];

    protected function casts(): array
    {
        return [
            //'start_hour' => 'time',
            //'end_hour' => 'time',
            'is_holiday' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
