<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sprint extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sprints';

    protected $fillable = [
        'project_id',
        'number',
        'start_date',
        'end_date',
        'teamcategory_id',
        'is_concluded',
        'is_pilot',
        'is_billing',
        'has_csv',
        'is_planningdone',
        'is_estimatedone',
        'is_reviewdone',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_concluded' => 'boolean',
            'is_pilot' => 'boolean',
            'is_billing' => 'boolean',
            'has_csv' => 'boolean',
            'is_planningdone' => 'boolean',
            'is_estimatedone' => 'boolean',
            'is_reviewdone' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function teamCategory(): BelongsTo
    {
        return $this->belongsTo(TeamCategory::class, 'teamcategory_id');
    }

    public function capacities(): HasMany
    {
        return $this->hasMany(Capacity::class, 'sprint_id');
    }
}
