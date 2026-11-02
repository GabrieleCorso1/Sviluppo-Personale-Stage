<?php

namespace App\Models;

use App\Support\ProjectFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'csv_path',
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

    public function hasCsvFile(): bool
    {
        return ProjectFiles::exists($this->csv_path, 'sprints/csv');
    }

    public function csvStatus(): string
    {
        if ($this->hasCsvFile()) {
            return 'uploaded';
        }

        return $this->is_billing ? 'not_required' : ($this->is_concluded ? 'missing' : 'pending');
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
