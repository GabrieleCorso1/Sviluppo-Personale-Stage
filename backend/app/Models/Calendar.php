<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'typology_id',
    'start_date',
    'end_date',
    'start_time',
    'end_time',
    'status',
    'is_smart',
    'notes',
])]
class Calendar extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'calendar';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'is_smart' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typology(): BelongsTo
    {
        return $this->belongsTo(CalendarTypology::class, 'typology_id');
    }
}
