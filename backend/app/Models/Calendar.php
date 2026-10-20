<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Calendar extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'calendar';

    protected $fillable = [
        'user_id',
        'typology_id',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'status',
        'is_smart',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_smart' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typology(): BelongsTo
    {
        return $this->belongsTo(
            CalendarTypology::class,
            'typology_id'
        );
    }
}
