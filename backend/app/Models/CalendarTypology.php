<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'alias',
])]
class CalendarTypology extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'calendar_typology';

    public function calendar(): HasMany
    {
        return $this->hasMany(Calendar::class, 'typology_id');
    }
}
