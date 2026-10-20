<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarTypology extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'calendar_typology';

    protected $fillable = [
        'alias',
    ];

    public function calendarEntries(): HasMany
    {
        return $this->hasMany(
            Calendar::class,
            'typology_id'
        );
    }
}
